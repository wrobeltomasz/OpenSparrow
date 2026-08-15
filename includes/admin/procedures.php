<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\HttpException;

if ($action === 'list_procedures') {
    try {
        require_once __DIR__ . '/../../includes/db.php';
        $conn = db_connect();

        $sql = "SELECT r.routine_schema,
                       r.routine_name,
                       r.specific_name,
                       p.parameter_name,
                       p.data_type,
                       p.ordinal_position
                  FROM information_schema.routines r
                  LEFT JOIN information_schema.parameters p
                         ON p.specific_name = r.specific_name
                        AND p.specific_schema = r.specific_schema
                        AND p.parameter_mode IN ('IN', 'INOUT')
                 WHERE r.routine_type = 'PROCEDURE'
                   AND r.routine_schema NOT IN ('pg_catalog', 'information_schema')
                   AND r.routine_schema NOT LIKE 'pg_toast%'
                   AND r.routine_schema NOT LIKE 'pg_temp%'
                 ORDER BY r.routine_schema, r.routine_name, r.specific_name, p.ordinal_position";

        $res = @pg_query($conn, $sql);
        if (!$res) {
            admin_db_fail($conn, 'list_procedures');
        }

        $bySpecific = [];
        while ($row = pg_fetch_assoc($res)) {
            $key = (string)$row['specific_name'];
            if (!isset($bySpecific[$key])) {
                $bySpecific[$key] = [
                    'schema' => (string)$row['routine_schema'],
                    'name'   => (string)$row['routine_name'],
                    'params' => [],
                ];
            }

            if ($row['parameter_name'] === null && $row['ordinal_position'] === null) {
                continue;
            }
            $bySpecific[$key]['params'][] = [
                'name'     => (string)($row['parameter_name'] ?? ''),
                'type'     => (string)($row['data_type'] ?? ''),
                'position' => (int)$row['ordinal_position'],
            ];
        }

        admin_ok(['procedures' => array_values($bySpecific)]);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $e) {
        throw HttpException::fromStatus(
            500,
            (string) admin_error_message($e),
            ['status' => 'error', 'error' => admin_error_message($e)],
        );
    }
}
