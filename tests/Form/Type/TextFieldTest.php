<?php

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\TextField;
use PHPUnit\Framework\TestCase;

final class TextFieldTest extends TestCase
{
    private TextField $field;

    protected function setUp(): void
    {
        $this->field = new TextField();
    }

    private function col(
        string $type = 'text',
        ?string $regexp = null,
        ?string $msg = null
    ): ColumnConfig {
        return new ColumnConfig('name', $type, 'Name', false, false, true, [], [], $regexp, $msg);
    }

    public function testSupportsAllTypes(): void
    {
        $this->assertTrue($this->field->supports($this->col('text'), false));
        $this->assertTrue($this->field->supports($this->col('integer'), false));
        $this->assertTrue($this->field->supports($this->col('varchar'), true));
    }

    public function testBindWithValue(): void
    {
        $bv = $this->field->bind('name', ['name' => 'Alice']);
        $this->assertSame('Alice', $bv->value);
    }

    public function testBindEmptyReturnsNull(): void
    {
        $bv = $this->field->bind('name', ['name' => '']);
        $this->assertNull($bv->value);
    }

    public function testBindMissingKeyReturnsNull(): void
    {
        $bv = $this->field->bind('name', []);
        $this->assertNull($bv->value);
    }

    public function testRenderOutputsTextInput(): void
    {
        $html = $this->field->render($this->col(), 'Alice', new RenderContext(false));
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('value="Alice"', $html);
    }

    public function testRenderAddsValidationAttributes(): void
    {
        $col  = $this->col('text', '^[A-Z]+$', 'Must be uppercase');
        $html = $this->field->render($col, '', new RenderContext(false));
        $this->assertStringContainsString('data-pattern="^[A-Z]+$"', $html);
        $this->assertStringContainsString('data-message="Must be uppercase"', $html);
    }

    public function testRenderNoValidationAttributesWhenNull(): void
    {
        $html = $this->field->render($this->col(), '', new RenderContext(false));
        $this->assertStringNotContainsString('data-pattern', $html);
        $this->assertStringNotContainsString('data-message', $html);
    }

    public function testRenderReadonlyAddsAttr(): void
    {
        $html = $this->field->render($this->col(), 'Alice', new RenderContext(true));
        $this->assertStringContainsString('readonly', $html);
    }
}
