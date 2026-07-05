<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// DataSource.php — Backed enum for the database backend a table is routed to
// Postgres is the native store; Mysql marks tables served by the external MySQL
// gateway (config/mysql_gateway.json). Used by TableConfig::$source.

declare(strict_types=1);

namespace App\Domain\Schema;

enum DataSource: string
{
    case Postgres = 'postgres';
    case Mysql    = 'mysql';
}
