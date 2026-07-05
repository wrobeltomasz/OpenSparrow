<?php

declare(strict_types=1);

namespace Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates every languages/*.json file against en.json (the reference locale):
 *  - no UTF-8 BOM (a BOM makes json_decode() fail and silently empties the locale),
 *  - valid JSON object,
 *  - _meta.name and _meta.dir present,
 *  - full key parity with en.json (no missing, no extra keys),
 *  - identical {placeholder} sets for every translated value.
 */
final class LanguageFilesTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../languages';
    private const REFERENCE = 'en';

    /** Plural-form dictionaries are leaves, not nested namespaces. */
    private const PLURAL_FORMS = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /** @return array<string, array{0: string}> */
    public static function languageFileProvider(): array
    {
        $cases = [];
        foreach (glob(self::LANG_DIR . '/*.json') ?: [] as $path) {
            $cases[basename($path)] = [$path];
        }
        return $cases;
    }

    #[DataProvider('languageFileProvider')]
    public function testFileHasNoBom(string $path): void
    {
        $content = (string) file_get_contents($path);
        $this->assertStringStartsNotWith(
            "\xEF\xBB\xBF",
            $content,
            basename($path) . ' starts with a UTF-8 BOM; json_decode() rejects it and the locale loads empty.'
        );
    }

    #[DataProvider('languageFileProvider')]
    public function testFileIsValidJsonObject(string $path): void
    {
        $data = $this->decode($path);
        $this->assertIsArray($data, basename($path) . ' is not a valid JSON object.');
        $this->assertNotEmpty($data, basename($path) . ' decoded to an empty object.');
    }

    #[DataProvider('languageFileProvider')]
    public function testFileHasMeta(string $path): void
    {
        $data = $this->decode($path);
        $this->assertIsString($data['_meta']['name'] ?? null, basename($path) . ' is missing _meta.name.');
        $this->assertIsString($data['_meta']['dir'] ?? null, basename($path) . ' is missing _meta.dir.');
    }

    #[DataProvider('languageFileProvider')]
    public function testKeyParityWithReference(string $path): void
    {
        if (basename($path) === self::REFERENCE . '.json') {
            $this->assertTrue(true);
            return;
        }

        $reference = $this->flatten($this->withoutMeta($this->decode($this->referencePath())));
        $locale    = $this->flatten($this->withoutMeta($this->decode($path)));

        $missing = array_diff(array_keys($reference), array_keys($locale));
        $extra   = array_diff(array_keys($locale), array_keys($reference));

        $this->assertSame(
            [],
            array_values($missing),
            basename($path) . ' is missing keys present in en.json: ' . implode(', ', $missing)
        );
        $this->assertSame(
            [],
            array_values($extra),
            basename($path) . ' has keys absent from en.json (dead or misspelled): ' . implode(', ', $extra)
        );
    }

    #[DataProvider('languageFileProvider')]
    public function testPlaceholderParityWithReference(string $path): void
    {
        if (basename($path) === self::REFERENCE . '.json') {
            $this->assertTrue(true);
            return;
        }

        $reference = $this->flatten($this->withoutMeta($this->decode($this->referencePath())));
        $locale    = $this->flatten($this->withoutMeta($this->decode($path)));

        $mismatches = [];
        foreach ($locale as $key => $value) {
            if (!array_key_exists($key, $reference)) {
                continue; // reported by testKeyParityWithReference
            }
            $expected = $this->placeholders($reference[$key]);
            $actual   = $this->placeholders($value);
            if ($expected !== $actual) {
                $mismatches[] = sprintf(
                    '%s (en: {%s} vs locale: {%s})',
                    $key,
                    implode(', ', $expected),
                    implode(', ', $actual)
                );
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            basename($path) . ' has {placeholder} mismatches against en.json: ' . implode('; ', $mismatches)
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function referencePath(): string
    {
        return self::LANG_DIR . '/' . self::REFERENCE . '.json';
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $content = (string) file_get_contents($path);
        // Tolerate a BOM here so parity tests still run; testFileHasNoBom reports it.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function withoutMeta(array $data): array
    {
        unset($data['_meta']);
        return $data;
    }

    /**
     * Flatten the nested translation tree into dot-notation keys. A nested
     * object whose keys are all CLDR plural categories is a leaf value.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $isPluralLeaf = is_array($value)
                && $value !== []
                && array_diff(array_keys($value), self::PLURAL_FORMS) === [];
            if (is_array($value) && !$isPluralLeaf) {
                $flat += $this->flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = $value;
            }
        }
        return $flat;
    }

    /**
     * Extract the sorted set of {placeholder} names from a value
     * (plural leaves are scanned across all their forms).
     *
     * @param mixed $value
     * @return string[]
     */
    private function placeholders($value): array
    {
        $text = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
        preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = array_unique($m[1]);
        sort($names);
        return $names;
    }
}
