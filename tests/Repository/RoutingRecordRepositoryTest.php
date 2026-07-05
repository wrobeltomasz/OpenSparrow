<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Domain\Schema\DataSource;
use App\Domain\Schema\TableConfig;
use App\Form\RecordData;
use App\Repository\RecordRepositoryInterface;
use App\Repository\RoutingRecordRepository;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory RecordRepository: records how many times find() ran and
 * replays a canned row, so the router's dispatch + lazy-resolution behaviour can
 * be asserted without a live database.
 */
final class FakeRecordRepository implements RecordRepositoryInterface
{
    public int $findCalls = 0;

    /** @param array<string, mixed>|null $row */
    public function __construct(private readonly ?array $row = null)
    {
    }

    public function find(TableConfig $cfg, string|int $id): ?array
    {
        $this->findCalls++;
        return $this->row;
    }

    public function update(TableConfig $cfg, string|int $id, RecordData $data): void
    {
    }

    public function insert(TableConfig $cfg, RecordData $data): string|int
    {
        return 1;
    }

    public function subtables(TableConfig $cfg, string|int $parentId): array
    {
        return [];
    }
}

final class RoutingRecordRepositoryTest extends TestCase
{
    private function pgTable(): TableConfig
    {
        return new TableConfig('pg_widgets', 'app', 'PG Widgets', [], [], [], 'id', '', DataSource::Postgres, 'id');
    }

    private function mysqlTable(): TableConfig
    {
        return new TableConfig('my_widgets', 'app', 'MySQL Widgets', [], [], [], 'id', '', DataSource::Mysql, 'id');
    }

    public function testPostgresTableNeverInvokesTheMysqlFactory(): void
    {
        $factoryCalls = 0;
        $factory      = function () use (&$factoryCalls): ?RecordRepositoryInterface {
            $factoryCalls++;
            return new FakeRecordRepository(['id' => 99]);
        };
        $pg   = new FakeRecordRepository(['id' => 1, 'src' => 'pg']);
        $repo = new RoutingRecordRepository($pg, $factory);

        $row = $repo->find($this->pgTable(), 1);

        $this->assertSame(['id' => 1, 'src' => 'pg'], $row);
        $this->assertSame(0, $factoryCalls, 'PostgreSQL routing must never build the MySQL connection');
    }

    public function testMysqlTableRoutesToTheLazilyBuiltRepository(): void
    {
        $mysql   = new FakeRecordRepository(['id' => 99, 'src' => 'mysql']);
        $factory = fn(): RecordRepositoryInterface => $mysql;
        $repo    = new RoutingRecordRepository(new FakeRecordRepository(['id' => 1]), $factory);

        $this->assertSame(['id' => 99, 'src' => 'mysql'], $repo->find($this->mysqlTable(), 1));
    }

    public function testThrowingFactorySurfacesAsRuntimeExceptionNotAFatalError(): void
    {
        // The factory throws a raw \Error — the worst case the user flagged. It must
        // be contained and re-surfaced as the \RuntimeException page handlers catch,
        // never escape as an \Error that crashes the whole request.
        $repo = new RoutingRecordRepository(
            new FakeRecordRepository(),
            fn(): ?RecordRepositoryInterface => throw new \Error('driver missing')
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('routed to MySQL');
        $repo->find($this->mysqlTable(), 1);
    }

    public function testFactoryReturningNullThrowsRuntimeException(): void
    {
        $repo = new RoutingRecordRepository(new FakeRecordRepository(), fn(): ?RecordRepositoryInterface => null);

        $this->expectException(\RuntimeException::class);
        $repo->find($this->mysqlTable(), 1);
    }

    public function testThrowingFactoryIsNotRetriedOnSubsequentCalls(): void
    {
        $factoryCalls = 0;
        $repo         = new RoutingRecordRepository(
            new FakeRecordRepository(),
            function () use (&$factoryCalls): ?RecordRepositoryInterface {
                $factoryCalls++;
                throw new \RuntimeException('connect refused');
            }
        );

        foreach ([1, 2] as $id) {
            try {
                $repo->find($this->mysqlTable(), $id);
                $this->fail('Expected RuntimeException for unconfigured MySQL table');
            } catch (\RuntimeException $e) {
                // expected on every call
            }
        }

        $this->assertSame(1, $factoryCalls, 'Failed connection must resolve once, not retry per operation');
    }

    public function testEagerInstanceConstructorStillSupported(): void
    {
        $mysql = new FakeRecordRepository(['id' => 5, 'src' => 'mysql']);
        $repo  = new RoutingRecordRepository(new FakeRecordRepository(['id' => 1, 'src' => 'pg']), $mysql);

        $this->assertSame(['id' => 5, 'src' => 'mysql'], $repo->find($this->mysqlTable(), 1));
        $this->assertSame(['id' => 1, 'src' => 'pg'], $repo->find($this->pgTable(), 1));
    }

    public function testNullMysqlConstructorKeepsPostgresWorkingAndErrorsOnlyForMysql(): void
    {
        $repo = new RoutingRecordRepository(new FakeRecordRepository(['id' => 1, 'src' => 'pg']));

        $this->assertSame(['id' => 1, 'src' => 'pg'], $repo->find($this->pgTable(), 1));

        $this->expectException(\RuntimeException::class);
        $repo->find($this->mysqlTable(), 1);
    }
}
