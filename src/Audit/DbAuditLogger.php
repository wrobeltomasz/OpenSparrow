<?php

declare(strict_types=1);

namespace App\Audit;

use App\Persistence\PgConnection;

final readonly class DbAuditLogger
{
    public function __construct(private PgConnection $conn)
    {
    }

    public function log(int $userId, string $action, string $table, int $recordId): ?int
    {
        $sql = 'INSERT INTO ' . sys_table('users_log')
             . ' (user_id, action, target_table, record_id) VALUES ($1, $2, $3, $4) RETURNING id';
        $res = @pg_query_params($this->conn->native(), $sql, [$userId, $action, $table, $recordId]);
        if ($res && ($row = pg_fetch_row($res))) {
            return (int) $row[0];
        }
        return null;
    }
}
