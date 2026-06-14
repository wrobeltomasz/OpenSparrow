<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;
use App\Form\BoundValue;
use App\Form\RecordData;
use App\Persistence\MysqlConnection;
use App\Repository\MysqlRecordRepository;
use PHPUnit\Framework\TestCase;

/**
 * Captures the SQL + params handed to PDO, and replays a canned fetch row, so we
 * can assert the repository's type coercion without a live MySQL gateway.
 */
final class FakeMysqlStatement extends \PDOStatement
{
    /** @var array<int|string, mixed>|null */
    public ?array $executedParams = null;

    public function __construct(private readonly mixed $fetchRow = false)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->executedParams = $params;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetch(
        int $mode = \PDO::FETCH_DEFAULT,
        int $cursorOrientation = \PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->fetchRow;
    }
}

final class FakeMysqlPdo extends \PDO
{
    public string $lastSql = '';
    public ?FakeMysqlStatement $lastStatement = null;

    public function __construct(private readonly mixed $fetchRow = false)
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->lastSql = $query;
        return $this->lastStatement = new FakeMysqlStatement($this->fetchRow);
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId(?string $name = null): string|false
    {
        return '42';
    }
}

final class MysqlRecordRepositoryTest extends TestCase
{
    /** @param array<string, mixed> $row */
    private function table(): TableConfig
    {
        return new TableConfig(
            'widgets',
            'app',
            'Widgets',
            [
                'id'         => new ColumnConfig('id', 'integer', 'id'),
                'name'       => new ColumnConfig('name', 'text', 'name'),
                'active'     => new ColumnConfig('active', 'boolean', 'active'),
                'created_at' => new ColumnConfig('created_at', 'timestamp', 'created_at'),
            ],
            [],
            [],
            'id',
            '',
            'mysql',
            'id',
        );
    }

    public function testFindCastsTinyintBooleansToPhpBool(): void
    {
        $pdo  = new FakeMysqlPdo(['id' => 1, 'name' => 'Bob', 'active' => '1', 'created_at' => '2026-06-14 12:17:00']);
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $row = $repo->find($this->table(), 1);

        $this->assertIsArray($row);
        $this->assertTrue($row['active'], 'tinyint "1" should read back as bool true');
        $this->assertSame('Bob', $row['name'], 'non-bool columns are untouched');
    }

    public function testFindCastsZeroToFalseAndKeepsNull(): void
    {
        $pdo  = new FakeMysqlPdo(['id' => 1, 'name' => 'Bob', 'active' => '0', 'created_at' => null]);
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $row = $repo->find($this->table(), 1);

        $this->assertFalse($row['active']);
        $this->assertNull($row['created_at'], 'NULL stays NULL, not cast to a bool');
    }

    public function testFindReturnsNullWhenRowMissing(): void
    {
        $repo = new MysqlRecordRepository(new MysqlConnection(new FakeMysqlPdo(false)));

        $this->assertNull($repo->find($this->table(), 999));
    }

    public function testInsertConvertsBooleanToTinyint(): void
    {
        $pdo  = new FakeMysqlPdo();
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $repo->insert($this->table(), new RecordData([
            ['col' => 'name', 'bound' => new BoundValue('Bob')],
            ['col' => 'active', 'bound' => new BoundValue('true', 'boolean')],
        ]));

        $this->assertSame(['Bob', 1], $pdo->lastStatement->executedParams);
    }

    public function testUpdateNormalisesDatetimeLocalSeparator(): void
    {
        $pdo  = new FakeMysqlPdo();
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $repo->update($this->table(), 7, new RecordData([
            ['col' => 'created_at', 'bound' => new BoundValue('2026-06-14T12:17:00')],
        ]));

        // params = [created_at value, id]
        $this->assertSame('2026-06-14 12:17:00', $pdo->lastStatement->executedParams[0]);
        $this->assertSame('7', $pdo->lastStatement->executedParams[1]);
    }

    public function testTimestampStripsFractionAndTimezone(): void
    {
        $pdo  = new FakeMysqlPdo();
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $repo->insert($this->table(), new RecordData([
            ['col' => 'created_at', 'bound' => new BoundValue('2026-06-14T12:17:00.529+02:00')],
        ]));

        $this->assertSame('2026-06-14 12:17:00', $pdo->lastStatement->executedParams[0]);
    }

    public function testNullAndPlainTextPassThroughUnchanged(): void
    {
        $pdo  = new FakeMysqlPdo();
        $repo = new MysqlRecordRepository(new MysqlConnection($pdo));

        $repo->insert($this->table(), new RecordData([
            ['col' => 'name', 'bound' => new BoundValue('Plain text')],
            ['col' => 'created_at', 'bound' => new BoundValue(null)],
        ]));

        $this->assertSame(['Plain text', null], $pdo->lastStatement->executedParams);
    }
}
