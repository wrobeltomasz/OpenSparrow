<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Thin PDO wrapper for the MySQL gateway, used by the src/ record-routing layer.
 *
 * Deliberately NOT a ConnectionInterface: that contract returns PgSql types.
 * This wrapper exposes a small, driver-neutral surface (array rows) so the
 * MySQL record repository can route DML without touching PostgreSQL. The
 * connection recipe mirrors api.php::mysql_pdo_api().
 */
final readonly class MysqlConnection
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Build a connection from the MYSQL_* constants, or return null when the
     * gateway is not configured / unreachable. Never throws on connect, so
     * PostgreSQL-only requests degrade gracefully.
     */
    public static function fromConfig(): ?self
    {
        if (MYSQL_HOST === '' || MYSQL_DB === '' || MYSQL_USER === '') {
            return null;
        }
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
                MYSQL_HOST,
                MYSQL_PORT,
                MYSQL_DB,
                MYSQL_CONNECT_TIMEOUT
            );
            $pdo = new \PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => MYSQL_CONNECT_TIMEOUT,
            ]);
            return new self($pdo);
        } catch (\PDOException $e) {
            error_log('[mysql] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Run a prepared statement. PDOException is rethrown as RuntimeException so
     * callers catch the same type they do for PgConnection and no driver detail
     * leaks past the page error handlers.
     *
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException('Query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
