<?php

declare(strict_types=1);

namespace Tests\Form;

use App\Form\BoundValue;
use PHPUnit\Framework\TestCase;

final class BoundValueTest extends TestCase
{
    public function testPlaceholderWithoutCast(): void
    {
        $bv = new BoundValue('hello');
        $this->assertSame('$1', $bv->placeholder(1));
        $this->assertSame('$3', $bv->placeholder(3));
    }

    public function testPlaceholderWithCast(): void
    {
        $bv = new BoundValue('true', 'boolean');
        $this->assertSame('$1::boolean', $bv->placeholder(1));
    }

    public function testValueAndCastAreReadable(): void
    {
        $bv = new BoundValue(42, 'integer');
        $this->assertSame(42, $bv->value);
        $this->assertSame('integer', $bv->cast);
    }

    public function testNullValue(): void
    {
        $bv = new BoundValue(null);
        $this->assertNull($bv->value);
        $this->assertNull($bv->cast);
    }
}
