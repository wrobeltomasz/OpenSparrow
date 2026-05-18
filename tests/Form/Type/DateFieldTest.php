<?php

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\DateField;
use PHPUnit\Framework\TestCase;

final class DateFieldTest extends TestCase
{
    private DateField $field;

    protected function setUp(): void
    {
        $this->field = new DateField();
    }

    private function col(string $type = 'date'): ColumnConfig
    {
        return new ColumnConfig('dob', $type, 'Date of Birth');
    }

    public function testSupportsDateType(): void
    {
        $this->assertTrue($this->field->supports($this->col('date'), false));
    }

    public function testDoesNotSupportText(): void
    {
        $this->assertFalse($this->field->supports($this->col('text'), false));
    }

    public function testDoesNotSupportTimestamp(): void
    {
        $this->assertFalse($this->field->supports($this->col('timestamp'), false));
    }

    public function testBindWithValue(): void
    {
        $bv = $this->field->bind('dob', ['dob' => '2026-01-15']);
        $this->assertSame('2026-01-15', $bv->value);
        $this->assertNull($bv->cast);
    }

    public function testBindEmptyStringReturnsNull(): void
    {
        $bv = $this->field->bind('dob', ['dob' => '']);
        $this->assertNull($bv->value);
    }

    public function testBindMissingKeyReturnsNull(): void
    {
        $bv = $this->field->bind('dob', []);
        $this->assertNull($bv->value);
    }

    public function testRenderOutputsDateInput(): void
    {
        $html = $this->field->render($this->col(), '2026-01-15', new RenderContext(false));
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('value="2026-01-15"', $html);
    }

    public function testRenderReadonlyField(): void
    {
        $html = $this->field->render($this->col(), '2026-01-15', new RenderContext(true));
        $this->assertStringContainsString('readonly', $html);
    }
}
