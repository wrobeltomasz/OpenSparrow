<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testIdentifierIsQuoted(): void
    {
        $this->assertSame('"users"', Sql::ident('users'));
    }

    public function testEmbeddedDoubleQuoteIsDoubled(): void
    {
        $this->assertSame('"we""ird"', Sql::ident('we"ird'));
    }

    public function testQualifiedNameQuotesBothParts(): void
    {
        $this->assertSame('"app"."spw_files"', Sql::qualified('app', 'spw_files'));
    }

    public function testIntArrayCoercesEveryElement(): void
    {
        $this->assertSame('{1,2,0,7}', Sql::intArray([1, '2', 'abc', 7.9]));
    }

    public function testIntArrayOfEmptyListIsEmptyLiteral(): void
    {
        $this->assertSame('{}', Sql::intArray([]));
    }
}
