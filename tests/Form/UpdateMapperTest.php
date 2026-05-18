<?php

declare(strict_types=1);

namespace Tests\Form;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;
use App\Form\BoundValue;
use App\Form\FieldTypeInterface;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use App\Form\UpdateMapper;
use PHPUnit\Framework\TestCase;

final class UpdateMapperTest extends TestCase
{
    private function passthroughType(): FieldTypeInterface
    {
        return new class implements FieldTypeInterface {
            public function supports(ColumnConfig $col, bool $hasForeignKey): bool
            {
                return true;
            }
            public function bind(string $colName, array $postData): BoundValue
            {
                return new BoundValue($postData[$colName] ?? null);
            }
            public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string
            {
                return '';
            }
        };
    }

    public function testFromPostBuildsBindingsForWritableColumns(): void
    {
        $col   = new ColumnConfig('name', 'text', 'Name');
        $table = new TableConfig('users', 'app', 'Users', ['name' => $col], [], []);

        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $rd     = $mapper->fromPost($table, ['name' => 'Alice']);

        $this->assertFalse($rd->isEmpty());
        $this->assertSame('name', $rd->bindings[0]['col']);
        $this->assertSame('Alice', $rd->bindings[0]['bound']->value);
    }

    public function testFromPostSkipsPrimaryKey(): void
    {
        $id    = new ColumnConfig('id', 'integer', 'ID');
        $name  = new ColumnConfig('name', 'text', 'Name');
        $table = new TableConfig('users', 'app', 'Users', ['id' => $id, 'name' => $name], [], []);

        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $rd     = $mapper->fromPost($table, ['id' => '99', 'name' => 'Bob']);

        $cols = array_column($rd->bindings, 'col');
        $this->assertNotContains('id', $cols);
        $this->assertContains('name', $cols);
    }

    public function testFromPostReturnsEmptyRecordWhenNoWritableColumns(): void
    {
        $table  = new TableConfig('users', 'app', 'Users', [], [], []);
        $mapper = new UpdateMapper(new FieldTypeRegistry([$this->passthroughType()]));
        $rd     = $mapper->fromPost($table, ['name' => 'Alice']);
        $this->assertTrue($rd->isEmpty());
    }

    public function testFromPostPassesFkFlagToRegistry(): void
    {
        $type = new class implements FieldTypeInterface {
            public array $seenFk = [];
            public function supports(ColumnConfig $col, bool $hasFk): bool
            {
                $this->seenFk[$col->name] = $hasFk;
                return true;
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

        $col   = new ColumnConfig('user_id', 'integer', 'User');
        $table = new TableConfig(
            'orders', 'app', 'Orders',
            ['user_id' => $col],
            ['user_id' => ['table' => 'users']],
            []
        );

        $mapper = new UpdateMapper(new FieldTypeRegistry([$type]));
        $mapper->fromPost($table, []);
        $this->assertTrue($type->seenFk['user_id']);
    }
}
