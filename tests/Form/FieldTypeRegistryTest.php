<?php

declare(strict_types=1);

namespace Tests\Form;

use App\Domain\Schema\ColumnConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private function makeType(bool $supports): FieldTypeInterface
    {
        return new class ($supports) implements FieldTypeInterface {
            public function __construct(private readonly bool $s)
            {
            }
            public function supports(ColumnConfig $col, bool $hasForeignKey): bool
            {
                return $this->s;
            }
            public function bind(string $colName, array $postData): BoundValue
            {
                return new BoundValue(null);
            }
            public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string
            {
                return '';
            }
        };
    }

    public function testReturnsFirstMatchingType(): void
    {
        $first    = $this->makeType(false);
        $second   = $this->makeType(true);
        $registry = new FieldTypeRegistry([$first, $second]);
        $col      = new ColumnConfig('x', 'text', 'X');
        $this->assertSame($second, $registry->for($col, false));
    }

    public function testThrowsLogicExceptionWhenNoMatch(): void
    {
        $registry = new FieldTypeRegistry([$this->makeType(false)]);
        $col      = new ColumnConfig('x', 'text', 'X');
        $this->expectException(\LogicException::class);
        $registry->for($col, false);
    }

    public function testFirstMatchWinsOverLaterCandidates(): void
    {
        $first  = $this->makeType(true);
        $second = $this->makeType(true);
        $registry = new FieldTypeRegistry([$first, $second]);
        $col = new ColumnConfig('x', 'text', 'X');
        $this->assertSame($first, $registry->for($col, false));
    }
}
