<?php

declare(strict_types=1);

namespace Tests\Form;

use App\Form\BoundValue;
use App\Form\RecordData;
use PHPUnit\Framework\TestCase;

final class RecordDataTest extends TestCase
{
    public function testIsEmptyWhenNoBindings(): void
    {
        $rd = new RecordData([]);
        $this->assertTrue($rd->isEmpty());
    }

    public function testIsNotEmptyWithBindings(): void
    {
        $rd = new RecordData([
            ['col' => 'name', 'bound' => new BoundValue('Alice')],
        ]);
        $this->assertFalse($rd->isEmpty());
    }

    public function testBindingsArePreserved(): void
    {
        $bv = new BoundValue('test');
        $rd = new RecordData([['col' => 'name', 'bound' => $bv]]);
        $this->assertCount(1, $rd->bindings);
        $this->assertSame('name', $rd->bindings[0]['col']);
        $this->assertSame($bv, $rd->bindings[0]['bound']);
    }
}
