<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class I18n
{
    private static ?self $instance = null;

    private string $locale;
    private const string FALLBACK      = 'en';
    private const string LANGUAGES_DIR = __DIR__ . '/../languages/';

    private array $strings = [];

    private array $fallback = [];

    private function __construct(string $locale)
    {
        $this->locale   = $locale;
        $this->fallback = self::loadFileStatic(self::FALLBACK);
        $this->strings  = $locale !== self::FALLBACK
            ? self::loadFileStatic($locale)
            : $this->fallback;
    }

    public static function init(?string $override = null): void
    {
        self::$instance = new self(self::detectLocale($override));
    }

    private static function instance(): self
    {
        if (self::$instance === null) {
            self::init();
        }
        return self::$instance;
    }

    public static function locale(): string
    {
        return self::instance()->locale;
    }

    public static function detectLocale(?string $override = null): string
    {
        $available       = self::availableLocales();
        $currentVersion  = self::localeVersion();

        $sessionLocale = null;
        if (isset($_SESSION['locale'])) {
            $versionOk = $currentVersion === ''
                || (isset($_SESSION['locale_version']) && $_SESSION['locale_version'] === $currentVersion);
            if ($versionOk) {
                $sessionLocale = (string)$_SESSION['locale'];
            }
        }

        $candidates = array_filter([
            $override,
            isset($_GET['lang']) ? (string)$_GET['lang'] : null,
            $sessionLocale,
            isset($_SESSION['user_locale']) ? (string)$_SESSION['user_locale'] : null,
            self::defaultFromSettings(),
            self::fromAcceptLanguage(),
        ]);

        foreach ($candidates as $candidate) {
            $safe = self::sanitize($candidate);
            if (in_array($safe, $available, true)) {
                if (
                    isset($_GET['lang'])
                    && $safe === self::sanitize((string)$_GET['lang'])
                    && session_status() === PHP_SESSION_ACTIVE
                ) {
                    $_SESSION['locale']         = $safe;
                    $_SESSION['locale_version'] = $currentVersion;
                }
                return $safe;
            }
        }

        return self::FALLBACK;
    }

    public static function t(string $key, array $variables = [], ?int $count = null): string
    {
        $instance  = self::instance();
        $value = $instance->resolve($key, $instance->strings)
              ?? $instance->resolve($key, $instance->fallback);

        if ($value === null) {
            if (defined('APP_ENV') && APP_ENV === 'development') {
                error_log("i18n missing key: {$key} [{$instance->locale}]");
            }
            return $key;
        }

        if (is_array($value) && $count !== null) {
            $form  = self::pluralForm($instance->locale, $count);
            $value = $value[$form] ?? $value['other'] ?? reset($value);
        }

        if (!is_string($value)) {
            return $key;
        }

        if ($variables !== []) {
            $value = (string)preg_replace_callback(
                '/\{(\w+)\}/',
                static fn(array $matches): string => isset($variables[$matches[1]])
                    ? (string)$variables[$matches[1]]
                    : $matches[0],
                $value
            );
        }

        return $value;
    }

    public static function flatBundle(): array
    {
        $instance   = self::instance();
        $merged = array_replace_recursive($instance->fallback, $instance->strings);
        unset($merged['_meta']);
        return self::flatten($merged);
    }

    public static function availableLanguageMeta(): array
    {
        $meta = [];
        foreach (self::availableLocales() as $locale) {
            $data        = self::loadFileStatic($locale);
            $meta[$locale] = [
                'name' => is_string($data['_meta']['name'] ?? null) ? $data['_meta']['name'] : $locale,
                'dir'  => is_string($data['_meta']['dir']  ?? null) ? $data['_meta']['dir']  : 'ltr',
            ];
        }
        return $meta;
    }

    private function resolve(string $key, array $tree): string|array|null
    {
        $node = $tree;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return (is_string($node) || is_array($node)) ? $node : null;
    }

    private static function loadFileStatic(string $locale): array
    {
        $path = self::LANGUAGES_DIR . self::sanitize($locale) . '.json';
        if (!is_file($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function availableLocales(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $files = glob(self::LANGUAGES_DIR . '*.json') ?: [];
        return $cache = array_map(
            static fn(string $languageFile): string => basename($languageFile, '.json'),
            $files
        );
    }

    public static function sanitize(string $locale): string
    {
        return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) ? $locale : self::FALLBACK;
    }

    private static function fromAcceptLanguage(): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (preg_match('/^([a-z]{2})/i', $header, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    private static function defaultFromSettings(): string
    {
        static $default = null;
        if ($default !== null) {
            return $default;
        }
        $lang = settings_value('default_language');
        if (is_string($lang)) {
            return $default = $lang;
        }
        return $default = self::FALLBACK;
    }

    private static function localeVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $storedVersion = settings_value('locale_version');
        if (is_string($storedVersion)) {
            return $version = $storedVersion;
        }
        return $version = '';
    }

    private static function pluralForm(string $locale, int $count): string
    {
        $absoluteCount = abs($count);
        return match (true) {
            in_array($locale, ['pl'], true)           => self::pluralPl($absoluteCount),
            in_array($locale, ['ru', 'uk'], true)     => self::pluralRu($absoluteCount),
            in_array($locale, ['cs', 'sk'], true)     => self::pluralCs($absoluteCount),
            in_array($locale, ['ro'], true)           => self::pluralRo($absoluteCount),
            in_array($locale, ['hr'], true)           => self::pluralRu($absoluteCount),
            in_array($locale, ['lt'], true)           => self::pluralLt($absoluteCount),
            in_array($locale, ['sl'], true)           => self::pluralSl($absoluteCount),
            in_array($locale, ['lv'], true)           => self::pluralLv($absoluteCount),
            default                                   => $absoluteCount === 1 ? 'one' : 'other',
        };
    }

    private static function pluralPl(int $count): string
    {
        if ($count === 1) {
            return 'one';
        }
        $modulo10  = $count % 10;
        $modulo100 = $count % 100;
        if ($modulo10 >= 2 && $modulo10 <= 4 && ($modulo100 < 10 || $modulo100 >= 20)) {
            return 'few';
        }
        return 'many';
    }

    private static function pluralRu(int $count): string
    {
        $modulo10  = $count % 10;
        $modulo100 = $count % 100;
        if ($modulo10 === 1 && $modulo100 !== 11) {
            return 'one';
        }
        if ($modulo10 >= 2 && $modulo10 <= 4 && ($modulo100 < 10 || $modulo100 >= 20)) {
            return 'few';
        }
        return 'many';
    }

    private static function pluralCs(int $count): string
    {
        if ($count === 1) {
            return 'one';
        }
        if ($count >= 2 && $count <= 4) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralRo(int $count): string
    {
        if ($count === 1) {
            return 'one';
        }
        $modulo100 = $count % 100;
        if ($count === 0 || ($modulo100 >= 2 && $modulo100 <= 19)) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralLt(int $count): string
    {
        $modulo10  = $count % 10;
        $modulo100 = $count % 100;
        if ($modulo10 === 1 && ($modulo100 < 11 || $modulo100 > 19)) {
            return 'one';
        }
        if ($modulo10 >= 2 && $modulo10 <= 9 && ($modulo100 < 11 || $modulo100 > 19)) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralSl(int $count): string
    {
        $modulo100 = $count % 100;
        if ($modulo100 === 1) {
            return 'one';
        }
        if ($modulo100 === 2) {
            return 'two';
        }
        if ($modulo100 === 3 || $modulo100 === 4) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralLv(int $count): string
    {
        $modulo10  = $count % 10;
        $modulo100 = $count % 100;
        if ($modulo10 === 1 && $modulo100 !== 11) {
            return 'one';
        }
        return 'other';
    }

    private static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $full       = $prefix !== '' ? "{$prefix}.{$key}" : (string)$key;
            $isPluralLeaf = is_array($value) && isset($value['one']);
            if (is_array($value) && !$isPluralLeaf) {
                $result += self::flatten($value, $full);
            } else {
                $result[$full] = is_string($value)
                    ? $value
                    : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }
        return $result;
    }
}

function t(string $key, array $variables = [], ?int $count = null): string
{
    return I18n::t($key, $variables, $count);
}
