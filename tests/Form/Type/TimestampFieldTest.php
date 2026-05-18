<?php

declare(strict_types=1);

namespace Tests\Form\Type;

use App\Domain\Schema\ColumnConfig;
use App\Form\RenderContext;
use App\Form\Type\TimestampField;
use PHPUnit\Framework\TestCase;

final class TimestampFieldTest extends TestCase
{
    private TimestampField $field;

    protected function setUp(): void
    {
        $this->field = new TimestampField();
    }

    private function col(string $type = 'timestamp'): ColumnConfig
    {
        return new ColumnConfig('created_at', $type, 'Created At');
    }

    public function testSupportsTimestamp(): void
    {
        $this->assertTrue($this->field->supports($this->col('timestamp'), false));
        $this->assertTrue($this->field->supports($this->col('timestamp with time zone'), false));
    }

    public function testDoesNotSupportDate(): void
    {
        $this->assertFalse($this->field->supports($this->col('date'), false));
    }

    public function testBindConvertsTSeparator(): void
    {
        $bv = $this->field->bind('created_at', ['created_at' => '2026-05-07T20:55:15']);
        $this->assertSame('2026-05-07 20:55:15', $bv->value);
    }

    public function testBindEmptyReturnsNull(): void
    {
        $bv = $this->field->bind('created_at', ['created_at' => '']);
        $this->assertNull($bv->value);
    }

    public function testBindMissingKeyReturnsNull(): void
    {
        $bv = $this->field->bind('created_at', []);
        $this->assertNull($bv->value);
    }

    public function testRenderStripsMilliseconds(): void
    {
        $html = $this->field->render($this->col(), '2026-05-07 20:55:15.208', new RenderContext(false));
        $this->assertStringContainsString('value="2026-05-07T20:55:15"', $html);
    }

    public function testRenderStripsTimezoneOffset(): void
    {
        $html = $this->field->render($this->col(), '2026-05-07 20:55:15+02', new RenderContext(false));
        $this->assertStringContainsString('value="2026-05-07T20:55:15"', $html);
    }

    public function testRenderStripsUtcZ(): void
    {
        $html = $this->field->render($this->col(), '2026-05-07 20:55:15Z', new RenderContext(false));
        $this->assertStringContainsString('value="2026-05-07T20:55:15"', $html);
    }

    public function testRenderOutputsDatetimeLocalInput(): void
    {
        $html = $this->field->render($this->col(), '2026-05-07 10:00:00', new RenderContext(false));
        $this->assertStringContainsString('type="datetime-local"', $html);
    }
}
