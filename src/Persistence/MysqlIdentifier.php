<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Backtick quoting for MySQL identifiers — the MySQL counterpart to Identifier
 * (which double-quotes for PostgreSQL). Embedded backticks are stripped, never
 * interpolated, so a compromised config value cannot break out of the quotes.
 */
final class MysqlIdentifier
{
    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }
}
