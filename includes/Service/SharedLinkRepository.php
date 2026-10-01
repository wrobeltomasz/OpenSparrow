<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class SharedLinkRepository
{
    private const CONFIG_KEY = 'shared_tables';

    public function __construct(
        private readonly SharedLinkValidator $validator = new SharedLinkValidator(),
        private readonly SharedTokenManager $tokenManager = new SharedTokenManager(),
    ) {
    }

    public function defaults(): array
    {
        return ['tables' => []];
    }

    public function load(): array
    {
        $row = \config_get_row(self::CONFIG_KEY);
        $config = is_array($row['value'] ?? null)
            ? array_merge($this->defaults(), $row['value'])
            : $this->defaults();
        $config['tables'] = is_array($config['tables'] ?? null) ? $config['tables'] : [];
        return ['config' => $config, 'version' => $row['version'] ?? 0];
    }

    public function entryByToken(string $token): ?array
    {
        foreach ($this->load()['config']['tables'] as $table => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($this->tokenManager->matches($entry, $token)) {
                return ['table' => (string) $table, 'entry' => $entry];
            }
        }
        return null;
    }

    public function save(array $data, ?int $expectedVersion, ?int $userId, array $schema): array
    {
        $existing = \config_get(self::CONFIG_KEY);
        $existing = is_array($existing) ? $existing : $this->defaults();
        $existingTables = is_array($existing['tables'] ?? null) ? $existing['tables'] : [];

        if (!array_key_exists('tables', $data) || !is_array($data['tables'])) {
            throw new \AdminApiMessage('Missing "tables" object.');
        }

        $tables = [];
        $generatedTokens = [];
        foreach ($data['tables'] as $table => $tableData) {
            $table = (string) $table;
            $this->validator->validateTableName($schema, $table);

            $enabled = SharedLinkValidator::coercBool($tableData['enabled'] ?? false);
            $regenerate = SharedLinkValidator::coercBool($tableData['regenerate'] ?? false);
            $previous = is_array($existingTables[$table] ?? null) ? $existingTables[$table] : [];

            $tokenHash = (string) ($previous['token_hash'] ?? '');
            $generatedBy = $previous['generated_by'] ?? null;
            $generatedAt = $previous['generated_at'] ?? null;

            if ($regenerate || ($enabled && $tokenHash === '')) {
                $generated = $this->tokenManager->generate();
                $tokenHash = $generated['token_hash'];
                $generatedTokens[$table] = $generated['token'];
                $generatedBy = $userId;
                $generatedAt = date('Y-m-d H:i:s');
            }

            if (!$enabled) {
                $tables[$table] = [
                    'enabled'      => false,
                    'token_hash'   => $tokenHash,
                    'generated_by' => $generatedBy,
                    'generated_at' => $generatedAt,
                ];
                continue;
            }

            $tables[$table] = [
                'enabled'      => true,
                'token_hash'   => $tokenHash,
                'generated_by' => $generatedBy,
                'generated_at' => $generatedAt,
            ];
        }

        $config = ['tables' => $tables];

        $result = \config_save(self::CONFIG_KEY, $config, $expectedVersion, $userId);
        if ($result['status'] === 'conflict') {
            return ['status' => 'conflict'];
        }
        if ($result['status'] !== 'ok') {
            return $result;
        }

        return [
            'status'          => 'ok',
            'version'         => $result['version'],
            'generated_tokens' => $generatedTokens,
        ];
    }
}
