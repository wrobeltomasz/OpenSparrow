<?php

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\BooleanField;
use PHPUnit\Framework\TestCase;

final class BooleanFieldTest extends TestCase
{
    private BooleanField $field;

    protected function setUp(): void
    {
        $this->field = new BooleanField();
    }

    private function col(string $type = 'boolean'): ColumnConfig
    {
        return new ColumnConfig('active', $type, 'Active');
    }

    public function testSupportsBoolTypes(): void
    {
        $this->assertTrue($this->field->supports($this->col('boolean'), false));
        $this->assertTrue($this->field->supports($this->col('bool'), false));
    }

    public function testDoesNotSupportNonBool(): void
    {
        $this->assertFalse($this->field->supports($this->col('text'), false));
        $this->assertFalse($this->field->supports($this->col('integer'), false));
    }

    public function testBindCheckedReturnsTrue(): void
    {
        $bv = $this->field->bind('active', ['active' => 'on']);
        $this->assertSame('true', $bv->value);
        $this->assertSame('boolean', $bv->cast);
    }

    public function testBindUncheckedReturnsFalse(): void
    {
        $bv = $this->field->bind('active', []);
        $this->assertSame('false', $bv->value);
        $this->assertSame('boolean', $bv->cast);
    }

    public function testRenderCheckedState(): void
    {
        $html = $this->field->render($this->col(), 'true', new RenderContext(false));
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function testRenderUncheckedState(): void
    {
        $html = $this->field->render($this->col(), 'false', new RenderContext(false));
        $this->assertStringNotContainsString('checked', $html);
    }

    public function testRenderLockedAddsHiddenAndDisabled(): void
    {
        $html = $this->field->render($this->col(), 'true', new RenderContext(true));
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }
}
