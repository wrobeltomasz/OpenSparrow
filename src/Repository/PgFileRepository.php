<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\ConnectionInterface;

final readonly class PgFileRepository implements FileRepositoryInterface
{
    public function __construct(private ConnectionInterface $conn)
    {
    }

    #[\Override]
    public function forRecord(string $table, string|int $id): array
    {
        $sql = 'SELECT uuid, display_name, name, type, size_bytes, created_at, tags
                FROM ' . sys_table('files') . '
                WHERE related_table = $1 AND related_id = $2 AND deleted_at IS NULL
                ORDER BY created_at DESC';

        $res = @pg_query_params($this->conn->native(), $sql, [$table, (string)$id]);
        if (!$res) {
            return [];
        }
        $files = [];
        while ($f = pg_fetch_assoc($res)) {
            $files[] = $f;
        }
        return $files;
    }
}
