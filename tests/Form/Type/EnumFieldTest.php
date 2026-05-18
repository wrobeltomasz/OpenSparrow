<?php

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\EnumField;
use PHPUnit\Framework\TestCase;

final class EnumFieldTest extends TestCase
{
    private EnumField $field;

    protected function setUp(): void
    {
        $this->field = new EnumField();
    }

    private function col(array $options = [], array $colors = []): ColumnConfig
    {
        return new ColumnConfig('status', 'enum', 'Status', false, false, true, $options, $colors);
    }

    public function testSupportsEnum(): void
    {
        $this->assertTrue($this->field->supports($this->col(), false));
    }

    public function testDoesNotSupportText(): void
    {
        $col = new ColumnConfig('name', 'text', 'Name');
        $this->assertFalse($this->field->supports($col, false));
    }

    public function testBindWithValue(): void
    {
        $bv = $this->field->bind('status', ['status' => 'active']);
        $this->assertSame('active', $bv->value);
        $this->assertNull($bv->cast);
    }

    public function testBindEmptyReturnsNull(): void
    {
        $bv = $this->field->bind('status', ['status' => '']);
        $this->assertNull($bv->value);
    }

    public function testRenderUnlockedOutputsSelect(): void
    {
        $col  = $this->col(['active', 'inactive']);
        $html = $this->field->render($col, 'active', new RenderContext(false));
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('active', $html);
        $this->assertStringContainsString('inactive', $html);
    }

    public function testRenderUnlockedMarksCurrentValueSelected(): void
    {
        $col  = $this->col(['active', 'inactive']);
        $html = $this->field->render($col, 'inactive', new RenderContext(false));
        $this->assertMatchesRegularExpression('/<option[^>]*value="inactive"[^>]*selected/', $html);
    }

    public function testRenderLockedOutputsBadge(): void
    {
        $col  = $this->col(['active'], ['active' => '#00ff00']);
        $html = $this->field->render($col, 'active', new RenderContext(true));
        $this->assertStringContainsString('enum-badge', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }
}
