<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageFilesTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../languages';
    private const REFERENCE = 'en';

    private const PLURAL_FORMS = ['zero', 'one', 'two', 'few', 'many', 'other'];

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
                continue;
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

    private function referencePath(): string
    {
        return self::LANG_DIR . '/' . self::REFERENCE . '.json';
    }

    private function decode(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function withoutMeta(array $data): array
    {
        unset($data['_meta']);
        return $data;
    }

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

    private function placeholders($value): array
    {
        $text = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
        preg_match_all('/\{(\w+)\}/', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);
        return $names;
    }
}
