<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class EnumWhitelistGuardTest extends TestCase
{
    private const RECORD_FILE    = 'includes/frontapi/record.php';
    private const MASSEDIT_FILE  = 'includes/Controller/Api/MassEditController.php';
    private const HELPERS_FILE  = 'includes/api_helpers.php';

    private const ENUM_CALL = 'validate_column_enum(';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/api_helpers.php';
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function code(string $relativePath): string
    {
        $path = self::root() . '/' . $relativePath;
        $this->assertFileExists($path);

        $output = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $output .= $token[1];
                continue;
            }
            $output .= $token;
        }
        return (string) preg_replace('/\s+/', ' ', $output);
    }

    public function testValueOutsideEnumOptionsIsRejected(): void
    {
        $column = ['type' => 'enum', 'options' => ['New', 'Contacted', 'Qualified', 'Lost']];
        $this->assertSame('Invalid format', validate_column_enum($column, 'Bogus'));
    }

    public function testValueInsideEnumOptionsPasses(): void
    {
        $column = ['type' => 'enum', 'options' => ['New', 'Contacted', 'Qualified', 'Lost']];
        $this->assertNull(validate_column_enum($column, 'Qualified'));
    }

    public function testEmptyValueSkipsWhitelist(): void
    {
        $column = ['type' => 'enum', 'options' => ['New']];
        $this->assertNull(validate_column_enum($column, ''));
    }

    public function testNullValueSkipsWhitelist(): void
    {
        $column = ['type' => 'enum', 'options' => ['New']];
        $this->assertNull(validate_column_enum($column, null));
    }

    public function testValidationMessageOverridesGenericOne(): void
    {
        $column = [
            'type' => 'enum',
            'options' => ['New'],
            'validation_message' => 'Pick a valid status',
        ];
        $this->assertSame('Pick a valid status', validate_column_enum($column, 'Bogus'));
    }

    public function testForeignKeyExemptColumnPassesIdValue(): void
    {
        $column = ['type' => 'enum', 'options' => ['New']];
        $this->assertNull(validate_column_enum($column, '42', true));
    }

    public function testNonEnumColumnTypeSkipsWhitelist(): void
    {
        $this->assertNull(validate_column_enum(['type' => 'text', 'options' => []], 'anything'));
        $this->assertNull(validate_column_enum(['type' => 'number'], '99999'));
    }

    public function testNumericOptionsAreComparedAsStrings(): void
    {
        $column = ['type' => 'enum', 'options' => [1, 2, 3]];
        $this->assertNull(validate_column_enum($column, '2'));
        $this->assertSame('Invalid format', validate_column_enum($column, '5'));
    }

    public function testPatchRouteValidatesEnumBeforeRegexpAndBeforeCapture(): void
    {
        $source = $this->code(self::RECORD_FILE);
        $patch  = strpos($source, 'function frontapi_record_patch(');

        $this->assertNotFalse($patch, 'frontapi_record_patch() is missing from includes/frontapi/record.php.');

        $enum    = strpos($source, self::ENUM_CALL, $patch);
        $regexp  = strpos($source, 'validate_column_regexp(', $patch);
        $capture = strpos($source, 'auto_capture_old_record(', $patch);
        $update  = strpos($source, 'UPDATE ', $patch);

        $this->assertIsInt($enum, 'frontapi_record_patch() must call validate_column_enum() — the grid PATCH path '
            . 'writes straight to the database and a hand-crafted POST bypasses the HTML <select>.');
        $this->assertIsInt($regexp, 'frontapi_record_patch() must keep validate_column_regexp().');
        $this->assertLessThan($regexp, $enum, 'In frontapi_record_patch() the enum whitelist must run before '
            . 'validate_column_regexp() (next to it, same validation family).');
        $this->assertLessThan($capture, $enum, 'In frontapi_record_patch() the enum whitelist must run before '
            . 'auto_capture_old_record() — a rejected value must not trigger snapshot/automation side effects.');
        $this->assertLessThan($update, $enum, 'In frontapi_record_patch() the enum whitelist must run before the '
            . 'UPDATE statement.');
    }

    public function testPatchRouteRespectsForeignKeyExemption(): void
    {
        $source = $this->code(self::RECORD_FILE);
        $patch  = strpos($source, 'function frontapi_record_patch(');
        $insert = strpos($source, 'function frontapi_record_insert(');
        $patch  = $patch !== false && ($insert === false || $patch < $insert) ? $patch : $insert;

        $slice   = $patch !== false && $insert !== false
            ? substr($source, $patch, $insert - $patch)
            : substr($source, $patch ?: 0);
        $enumPos = strpos($slice, self::ENUM_CALL);

        $this->assertIsInt($enumPos, 'The grid PATCH path must call validate_column_enum() with the FK flag — an FK '
            . 'value is an ID, not an enum option, and must skip the whitelist.');
        $this->assertStringContainsString(
            'validate_column_enum($tableConfig[\'columns\'][$column], $value, $hasFk)',
            $slice,
            'The grid PATCH path must pass the foreign_keys membership ($hasFk) to validate_column_enum().'
        );
    }

    public function testInsertRouteValidatesEnumPerColumnWithFkFlag(): void
    {
        $source = $this->code(self::RECORD_FILE);
        $insert = strpos($source, 'function frontapi_record_insert(');

        $this->assertNotFalse($insert, 'frontapi_record_insert() is missing from includes/frontapi/record.php.');

        $slice   = substr($source, $insert);
        $enumPos = strpos($slice, self::ENUM_CALL);

        $this->assertIsInt($enumPos, 'frontapi_record_insert() must call validate_column_enum() inside the column '
            . 'loop — inserted enum values arrive from a hand-crafted POST, not from the HTML <select>.');
        $this->assertStringContainsString(
            'validate_column_enum($columnConfig, $value, $hasFk)',
            $slice,
            'frontapi_record_insert() must pass the per-column FK flag to validate_column_enum().'
        );

        $enum   = $enumPos + $insert;
        $regexp = strpos($source, 'validate_column_regexp(', $insert);
        $this->assertLessThan($regexp, $enum, 'In frontapi_record_insert() the enum whitelist must run before '
            . 'validate_column_regexp().');
    }

    public function testMassEditApplyValidatesEnumBeforeRegexp(): void
    {
        $source = $this->code(self::MASSEDIT_FILE);
        $apply  = strpos($source, 'private function apply(): void');

        $this->assertNotFalse($apply, 'MassEditController::apply() is missing.');

        $enum   = strpos($source, self::ENUM_CALL, $apply);
        $regexp = strpos($source, 'validate_column_regexp(', $apply);
        $begin  = strpos($source, '@pg_query($this->conn, \'BEGIN\')', $apply);

        $this->assertIsInt($enum, 'MassEditController::apply() must call validate_column_enum() — the mass-edit '
            . 'payload writes straight to the database.');
        $this->assertLessThan($regexp, $enum, 'In MassEditController::apply() the enum whitelist must run before '
            . 'validate_column_regexp().');
        $this->assertLessThan($begin, $enum, 'In MassEditController::apply() the enum whitelist must run before the '
            . 'transaction opens — a rejected value must not reach the UPDATE.');
        $this->assertStringContainsString(
            'validate_column_enum($columnConfig, $value, $hasFk)',
            substr($source, $apply, ($begin ?: strlen($source)) - $apply),
            'MassEditController::apply() must pass the FK flag to validate_column_enum().'
        );
    }

    public function testHelperKeepsTypeAndForeignKeyGuards(): void
    {
        $source = $this->code(self::HELPERS_FILE);
        $fn     = strpos($source, 'function validate_column_enum(');

        $this->assertNotFalse(
            $fn,
            'validate_column_enum() is missing from includes/api_helpers.php.'
        );

        $slice = substr($source, $fn, 800);
        $this->assertStringContainsString(
            "str_starts_with(\$type, 'enum')",
            $slice,
            'validate_column_enum() must keep its own type guard (str_starts_with on the column type) — '
            . 'callers pass every column, not only enum columns.'
        );
        $this->assertStringContainsString(
            '$hasForeignKey',
            $slice,
            'validate_column_enum() must keep the $hasForeignKey parameter — an FK value is an ID, not an enum option.'
        );
    }
}