<?php

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
