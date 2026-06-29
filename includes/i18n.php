<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// i18n.php — Internationalisation engine (PHP side) with pluralisation and locale detection
// Loads language JSON files from languages/; supports variable interpolation {key} and CLDR plural rules (pl, ru, uk, cs, sk, ro, hr, lt, sl, lv)
// Detects locale via: GET ?lang= -> session (version-stamped) -> user preference -> settings.json -> Accept-Language -> 'en'
// Provides t() helper, I18n::flatBundle() for JS bridge, and availableLanguageMeta() for language switcher
// Initialised automatically via start_session()

declare(strict_types=1);

// settings_value() lives in config.php; required explicitly so i18n is self-sufficient
require_once __DIR__ . '/config.php';

/**
 * Lightweight i18n engine — zero external dependencies.
 *
 * Bootstrap (called automatically from start_session()):
 *   I18n::init()
 *
 * Usage:
 *   t('common.save')
 *   t('grid.showing', ['from' => 1, 'to' => 10, 'total' => 42])
 *   t('files.count', ['count' => 3], 3)
 *   I18n::locale()  → 'pl'
 */
final class I18n
{
    private static ?self $instance = null;

    private string $locale;
    private const FALLBACK      = 'en';
    private const LANGUAGES_DIR = __DIR__ . '/../languages/';

    /** @var array<string,mixed> */
    private array $strings = [];
    /** @var array<string,mixed> */
    private array $fallback = [];

    private function __construct(string $locale)
    {
        $this->locale   = $locale;
        $this->fallback = self::loadFileStatic(self::FALLBACK);
        $this->strings  = $locale !== self::FALLBACK
            ? self::loadFileStatic($locale)
            : $this->fallback;
    }

    // ── Bootstrap ────────────────────────────────────────────────────────────

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

    // ── Locale detection (priority chain) ────────────────────────────────────

    /**
     * 1. explicit $override  2. GET ?lang=  3. session (version-validated)
     * 4. user pref from session  5. settings.json default  6. Accept-Language  7. 'en'
     *
     * Session locale is invalidated when admin changes default_language (locale_version bump).
     * This ensures a global default change takes effect for all active sessions immediately.
     */
    public static function detectLocale(?string $override = null): string
    {
        $available       = self::availableLocales();
        $currentVersion  = self::localeVersion();

        // Session locale is only valid when locale_version matches (or no versioning yet).
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
                // Persist explicit switch to session with current version stamp
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

    // ── Translation ───────────────────────────────────────────────────────────

    /**
     * Translate a dot-notation key with optional variable interpolation and pluralization.
     *
     * @param string               $key   e.g. "files.count"
     * @param array<string,scalar> $vars  e.g. ['count' => 3]
     * @param int|null             $count triggers plural-form selection when set
     */
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

    /**
     * Flat key→value map for the JS bridge (fallback merged with current locale).
     * Plural nodes serialized as JSON strings so JS can parse them.
     *
     * @return array<string,string>
     */
    public static function flatBundle(): array
    {
        $inst   = self::instance();
        $merged = array_replace_recursive($inst->fallback, $inst->strings);
        unset($merged['_meta']);
        return self::flatten($merged);
    }

    /**
     * @return array<string,array{name:string,dir:string}>
     */
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

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $tree */
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

    /** @return array<string,mixed> */
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
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return string[] locales that have a language file on disk */
    private static function availableLocales(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $langs = settings_value('available_languages');
        if (is_array($langs)) {
            return $cache = array_map('strval', $langs);
        }

        $files = glob(self::LANGUAGES_DIR . '*.json') ?: [];
        $cache = array_map(
            static fn(string $f): string => basename($f, '.json'),
            $files
        );
        return $cache;
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

    // ── CLDR plural rules ─────────────────────────────────────────────────────

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

    /**
     * @param  array<string,mixed> $array
     * @return array<string,string>
     */
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

/**
 * Global shorthand — keeps templates clean.
 *
 * @param array<string,scalar> $vars
 */
function t(string $key, array $vars = [], ?int $count = null): string
{
    return I18n::t($key, $vars, $count);
}
