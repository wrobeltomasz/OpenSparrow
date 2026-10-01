<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Service\SharedLinkRepository;
use App\Service\SharedLinkValidator;

if ($action === 'sharing_load') {
    require_once __DIR__ . '/../config_store.php';
    require_once __DIR__ . '/../autoload.php';

    $repository = new SharedLinkRepository();
    $loaded = $repository->load();
    $schema = config_get('schema') ?? [];
    $validator = new SharedLinkValidator();

    $tables = [];
    foreach ($validator->shareableTables($schema) as $table) {
        $entry = $loaded['config']['tables'][$table] ?? null;
        $tables[] = [
            'table'        => $table,
            'display_name' => $schema['tables'][$table]['display_name'] ?? $table,
            'enabled'      => is_array($entry) && !empty($entry['enabled']),
            'generated_by' => is_array($entry) ? ($entry['generated_by'] ?? null) : null,
            'generated_at' => is_array($entry) ? ($entry['generated_at'] ?? null) : null,
        ];
    }

    admin_ok([
        'tables'  => $tables,
        'version' => $loaded['version'],
    ]);
}

if ($action === 'sharing_save') {
    require_not_demo('Demo mode — writes disabled.');
    admin_try(static function (): void {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            admin_err('Invalid JSON.');
        }
        require_once __DIR__ . '/../config_store.php';
        require_once __DIR__ . '/../autoload.php';

        $repository = new SharedLinkRepository();
        $schema = config_get('schema') ?? [];
        $result = $repository->save(
            $data,
            admin_expected_version($data),
            admin_user_id(),
            is_array($schema) ? $schema : []
        );
        if ($result['status'] === 'conflict') {
            admin_err('Config was modified by someone else — reload and retry.');
        }
        if ($result['status'] !== 'ok') {
            admin_err($result['error'] ?? 'Failed to save shared links config.');
        }
        admin_ok([
            'version'         => $result['version'],
            'generated_tokens' => $result['generated_tokens'],
        ]);
    });
}
