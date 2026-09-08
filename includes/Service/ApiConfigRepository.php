<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class ApiConfigRepository
{
    public const MAX_APIS = 100;

    private const CONFIG_KEY = 'external_api';

    public function __construct(
        private readonly ApiConfigValidator $validator = new ApiConfigValidator(),
        private readonly ApiKeyManager $keyManager = new ApiKeyManager(),
    ) {
    }

    public function defaults(): array
    {
        return ['apis' => []];
    }

    public function redact(array $config): array
    {
        foreach ($config['apis'] as $apiIndex => $api) {
            if (!is_array($api)) {
                continue;
            }
            $config['apis'][$apiIndex]['key_configured'] = ($api['key_enc'] ?? '') !== '';
            unset($config['apis'][$apiIndex]['key_enc']);
            unset($config['apis'][$apiIndex]['key_hash']);
        }
        return $config;
    }

    public function load(): array
    {
        $row = \config_get_row(self::CONFIG_KEY);
        $config = is_array($row['value'] ?? null)
            ? array_merge($this->defaults(), $row['value'])
            : $this->defaults();
        $config['apis'] = is_array($config['apis'] ?? null) ? $config['apis'] : [];
        return ['config' => $this->redact($config), 'version' => $row['version'] ?? 0];
    }

    public function save(array $data, ?int $expectedVersion, ?int $userId): array
    {
        $existing = \config_get(self::CONFIG_KEY);
        $existing = is_array($existing) ? $existing : $this->defaults();
        $existingApisById = [];
        foreach ((array) ($existing['apis'] ?? []) as $existingApi) {
            if (is_array($existingApi) && ($existingApi['id'] ?? '') !== '') {
                $existingApisById[(string) $existingApi['id']] = $existingApi;
            }
        }

        $schema = \config_get('schema') ?? [];

        $apis = [];
        $generatedKeys = [];
        $seenIds = [];
        if (!array_key_exists('apis', $data) || !is_array($data['apis'])) {
            throw new \AdminApiMessage('Missing "apis" list.');
        }
        $submittedApis = array_values(array_filter(
            $data['apis'],
            static fn($api) => is_array($api)
        ));
        if (count($submittedApis) > self::MAX_APIS) {
            throw new \AdminApiMessage(
                'Too many APIs — the limit is ' . self::MAX_APIS . '.'
            );
        }
        foreach ($submittedApis as $api) {
            $validated = $this->validator->validate($api, $schema);

            $id = trim((string) ($api['id'] ?? ''));
            if ($id === '') {
                $id = 'api_' . bin2hex(random_bytes(6));
            }
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                throw new \AdminApiMessage('Invalid API id.');
            }
            if (isset($seenIds[$id])) {
                throw new \AdminApiMessage('Duplicate API id "' . $id . '".');
            }
            $seenIds[$id] = true;

            $previous = $existingApisById[$id] ?? [];
            $resolved = $this->keyManager->resolve($api, $previous);
            if ($resolved['generated'] !== null) {
                $generatedKeys[$id] = $resolved['generated'];
            }

            $apis[] = [
                'id'       => $id,
                'name'     => $validated['name'],
                'enabled'  => ApiConfigValidator::coerceBool($api['enabled'] ?? true, true),
                'key_enc'  => $resolved['key_enc'],
                'key_hash' => $resolved['key_hash'],
                'table'    => $validated['table'],
                'columns'  => $validated['columns'],
                'filters'  => $validated['filters'],
                'limit'    => $validated['limit'],
            ];
        }

        $config = ['apis' => $apis];

        $result = \config_save(self::CONFIG_KEY, $config, $expectedVersion, $userId);
        if ($result['status'] !== 'ok') {
            return $result;
        }

        return [
            'status'         => 'ok',
            'version'        => $result['version'],
            'apis'           => $this->redact($config),
            'generated_keys' => $generatedKeys,
        ];
    }
}
