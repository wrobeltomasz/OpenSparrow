<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\PgConnection;

final readonly class PgFileRepository
{
    public function __construct(private PgConnection $conn)
    {
    }

    public function forRecord(string $table, string|int $id): array
    {
        $sql = 'SELECT uuid, display_name, name, type, size_bytes, created_at, tags
                FROM ' . sys_table('files') . '
                WHERE related_table = $1 AND related_id = $2 AND deleted_at IS NULL
                  AND related_field IS DISTINCT FROM $3
                ORDER BY created_at DESC';

        // Gallery images live in the same table but belong to the Images tab, not here.
        $res = @pg_query_params($this->conn->native(), $sql, [$table, (string)$id, IMAGES_FIELD]);
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
