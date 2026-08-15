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

    public static function t(string $key, array $vars = [], ?int $count = null): string
    {
        $inst  = self::instance();
        $value = $inst->resolve($key, $inst->strings)
              ?? $inst->resolve($key, $inst->fallback);

        if ($value === null) {
            if (defined('APP_ENV') && APP_ENV === 'development') {
                error_log("i18n missing key: {$key} [{$inst->locale}]");
            }
            return $key;
        }

        if (is_array($value) && $count !== null) {
            $form  = self::pluralForm($inst->locale, $count);
            $value = $value[$form] ?? $value['other'] ?? reset($value);
        }

        if (!is_string($value)) {
            return $key;
        }

        if ($vars !== []) {
            $value = (string)preg_replace_callback(
                '/\{(\w+)\}/',
                static fn(array $m): string => isset($vars[$m[1]])
                    ? (string)$vars[$m[1]]
                    : $m[0],
                $value
            );
        }

        return $value;
    }

    public static function flatBundle(): array
    {
        $inst   = self::instance();
        $merged = array_replace_recursive($inst->fallback, $inst->strings);
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
            static fn(string $f): string => basename($f, '.json'),
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
        if (preg_match('/^([a-z]{2})/i', $header, $m)) {
            return strtolower($m[1]);
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
        $ver = settings_value('locale_version');
        if (is_string($ver)) {
            return $version = $ver;
        }
        return $version = '';
    }

    private static function pluralForm(string $locale, int $n): string
    {
        $abs = abs($n);
        return match (true) {
            in_array($locale, ['pl'], true)           => self::pluralPl($abs),
            in_array($locale, ['ru', 'uk'], true)     => self::pluralRu($abs),
            in_array($locale, ['cs', 'sk'], true)     => self::pluralCs($abs),
            in_array($locale, ['ro'], true)           => self::pluralRo($abs),
            in_array($locale, ['hr'], true)           => self::pluralRu($abs),
            in_array($locale, ['lt'], true)           => self::pluralLt($abs),
            in_array($locale, ['sl'], true)           => self::pluralSl($abs),
            in_array($locale, ['lv'], true)           => self::pluralLv($abs),
            default                                   => $abs === 1 ? 'one' : 'other',
        };
    }

    private static function pluralPl(int $n): string
    {
        if ($n === 1) {
            return 'one';
        }
        $m10  = $n % 10;
        $m100 = $n % 100;
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
            return 'few';
        }
        return 'many';
    }

    private static function pluralRu(int $n): string
    {
        $m10  = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
            return 'one';
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
            return 'few';
        }
        return 'many';
    }

    private static function pluralCs(int $n): string
    {
        if ($n === 1) {
            return 'one';
        }
        if ($n >= 2 && $n <= 4) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralRo(int $n): string
    {
        if ($n === 1) {
            return 'one';
        }
        $m100 = $n % 100;
        if ($n === 0 || ($m100 >= 2 && $m100 <= 19)) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralLt(int $n): string
    {
        $m10  = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && ($m100 < 11 || $m100 > 19)) {
            return 'one';
        }
        if ($m10 >= 2 && $m10 <= 9 && ($m100 < 11 || $m100 > 19)) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralSl(int $n): string
    {
        $m100 = $n % 100;
        if ($m100 === 1) {
            return 'one';
        }
        if ($m100 === 2) {
            return 'two';
        }
        if ($m100 === 3 || $m100 === 4) {
            return 'few';
        }
        return 'other';
    }

    private static function pluralLv(int $n): string
    {
        $m10  = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
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

function t(string $key, array $vars = [], ?int $count = null): string
{
    return I18n::t($key, $vars, $count);
}
