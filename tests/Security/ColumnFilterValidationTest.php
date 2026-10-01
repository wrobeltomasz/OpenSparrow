<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class ColumnFilterValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/api_helpers.php';
        require_once __DIR__ . '/../../includes/frontapi/list.php';
    }

    public function testNumberColumnAcceptsWholeNumbers(): void
    {
        $this->assertTrue(column_type_matches_value('number', '42'));
        $this->assertTrue(column_type_matches_value('integer', '-7'));
        $this->assertTrue(column_type_matches_value('numeric', '3.14'));
        $this->assertTrue(column_type_matches_value('float', '0'));
    }

    public function testNumberColumnRejectsNonNumericValues(): void
    {
        $this->assertFalse(column_type_matches_value('number', "' OR 1=1"));
        $this->assertFalse(column_type_matches_value('int4', 'abc'));
        $this->assertFalse(column_type_matches_value('numeric', '12,34'));
        $this->assertFalse(column_type_matches_value('number', 'NaN'));
    }

    public function testBooleanColumnAcceptsKnownLiterals(): void
    {
        $this->assertTrue(column_type_matches_value('boolean', 'true'));
        $this->assertTrue(column_type_matches_value('boolean', 'FALSE'));
        $this->assertTrue(column_type_matches_value('bool', '1'));
        $this->assertTrue(column_type_matches_value('bool', 't'));
    }

    public function testBooleanColumnRejectsAnythingElse(): void
    {
        $this->assertFalse(column_type_matches_value('boolean', 'yes'));
        $this->assertFalse(column_type_matches_value('boolean', "' OR 1=1"));
        $this->assertFalse(column_type_matches_value('bool', '2'));
    }

    public function testDateColumnAcceptsValidDates(): void
    {
        $this->assertTrue(column_type_matches_value('date', '2026-10-01'));
        $this->assertTrue(column_type_matches_value('date', '2024-02-29'));
    }

    public function testDateColumnRejectsInvalidDates(): void
    {
        $this->assertFalse(column_type_matches_value('date', '2026-13-45'));
        $this->assertFalse(column_type_matches_value('date', '2023-02-29'));
        $this->assertFalse(column_type_matches_value('date', '01-10-2026'));
        $this->assertFalse(column_type_matches_value('date', "' OR 1=1"));
    }

    public function testTimestampColumnAcceptsDateWithOptionalTime(): void
    {
        $this->assertTrue(column_type_matches_value('timestamp', '2026-10-01'));
        $this->assertTrue(column_type_matches_value('timestamp', '2026-10-01 12:30'));
        $this->assertTrue(column_type_matches_value('datetime', '2026-10-01T12:30:45'));
    }

    public function testTimestampColumnRejectsInvalidValues(): void
    {
        $this->assertFalse(column_type_matches_value('timestamp', '2026-10-01 25:00'));
        $this->assertFalse(column_type_matches_value('timestamp', '2026-10-01 12:30:61'));
        $this->assertFalse(column_type_matches_value('datetime', 'not-a-date'));
    }

    public function testTextAndEnumColumnsAcceptAnything(): void
    {
        $this->assertTrue(column_type_matches_value('text', "' OR 1=1"));
        $this->assertTrue(column_type_matches_value('text', '<img src=x>'));
        $this->assertTrue(column_type_matches_value('enum', 'anything'));
        $this->assertTrue(column_type_matches_value('', 'empty-type-passes-through'));
    }

    public function testBuildColumnFilterSqlThrowsOnTypeMismatch(): void
    {
        $parameters = [];

        $this->expectException(\App\Exception\BadRequestException::class);
        build_column_filter_sql('id', 'number', ['val' => "' OR 1=1"], $parameters);
    }

    public function testBuildColumnFilterSqlThrowsOnBadRangeBound(): void
    {
        $parameters = [];

        $this->expectException(\App\Exception\BadRequestException::class);
        build_column_filter_sql('created_at', 'date', ['from' => '2026-13-45'], $parameters);
    }

    public function testBuildColumnFilterSqlAcceptsWellTypedValues(): void
    {
        $parameters = [];

        $sql = build_column_filter_sql('value', 'number', ['min' => '10', 'max' => '20'], $parameters);

        $this->assertSame('("value" >= $1 AND "value" <= $2)', $sql);
        $this->assertSame(['10', '20'], $parameters);
    }

    public function testBuildColumnFilterSqlKeepsBooleanPayloadUnvalidated(): void
    {
        $parameters = [];

        $sql = build_column_filter_sql('active', 'boolean', ['bool' => 'true'], $parameters);

        $this->assertSame('("active" = $1)', $sql);
        $this->assertSame(['TRUE'], $parameters);
    }

    public function testBuildColumnFilterSqlRejectsBoolPayloadOnNonBooleanColumn(): void
    {
        $parameters = [];

        $this->expectException(\App\Exception\BadRequestException::class);
        build_column_filter_sql('id', 'number', ['bool' => 'true'], $parameters);
    }
}