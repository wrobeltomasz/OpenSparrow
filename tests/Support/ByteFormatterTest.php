<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ByteFormatter;
use PHPUnit\Framework\TestCase;

final class ByteFormatterTest extends TestCase
{
    public function testZero(): void
    {
        $this->assertSame('0 B', ByteFormatter::humanize(0));
    }

    public function testBytes(): void
    {
        $this->assertSame('512 B', ByteFormatter::humanize(512));
    }

    public function testKilobytes(): void
    {
        $this->assertSame('1 KB', ByteFormatter::humanize(1024));
    }

    public function testMegabytes(): void
    {
        $this->assertSame('1 MB', ByteFormatter::humanize(1024 * 1024));
    }

    public function testGigabytes(): void
    {
        $this->assertSame('1 GB', ByteFormatter::humanize(1024 * 1024 * 1024));
    }

    public function testFractional(): void
    {
        $this->assertSame('1.5 KB', ByteFormatter::humanize(1536));
    }
}
