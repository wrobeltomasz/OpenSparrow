<?php

declare(strict_types=1);

namespace Tests\Domain\Schema;

use App\Domain\Schema\ColumnConfig;
use PHPUnit\Framework\TestCase;

final class ColumnConfigTest extends TestCase
{
    public function testIsVirtual(): void
    {
        $col = new ColumnConfig('computed', 'virtual', 'Computed');
        $this->assertTrue($col->isVirtual());
    }

    public function testIsNotVirtual(): void
    {
        $col = new ColumnConfig('name', 'text', 'Name');
        $this->assertFalse($col->isVirtual());
    }

    public function testIsBool(): void
    {
        $this->assertTrue((new ColumnConfig('x', 'boolean', ''))->isBool());
        $this->assertTrue((new ColumnConfig('x', 'bool', ''))->isBool());
        $this->assertFalse((new ColumnConfig('x', 'text', ''))->isBool());
        $this->assertFalse((new ColumnConfig('x', 'integer', ''))->isBool());
    }

    public function testIsDate(): void
    {
        $this->assertTrue((new ColumnConfig('x', 'date', ''))->isDate());
        $this->assertFalse((new ColumnConfig('x', 'timestamp', ''))->isDate());
        $this->assertFalse((new ColumnConfig('x', 'text', ''))->isDate());
    }

    public function testIsTimestamp(): void
    {
        $this->assertTrue((new ColumnConfig('x', 'timestamp', ''))->isTimestamp());
        $this->assertTrue((new ColumnConfig('x', 'timestamp with time zone', ''))->isTimestamp());
        $this->assertFalse((new ColumnConfig('x', 'date', ''))->isTimestamp());
    }

    public function testIsEnum(): void
    {
        $this->assertTrue((new ColumnConfig('x', 'enum', ''))->isEnum());
        $this->assertTrue((new ColumnConfig('x', 'enum(a,b)', ''))->isEnum());
        $this->assertFalse((new ColumnConfig('x', 'text', ''))->isEnum());
    }
}
