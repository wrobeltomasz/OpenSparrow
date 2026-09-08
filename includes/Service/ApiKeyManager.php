<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class ApiKeyManager
{
    public function resolve(array $api, array $previous): array
    {
        $submittedKey = trim((string) ($api['key'] ?? ''));
        $regenerate = ApiConfigValidator::coerceBool($api['key_regenerate'] ?? false);

        if ($submittedKey !== '' && !$regenerate) {
            return [
                'key_enc'  => \secret_encrypt($submittedKey),
                'key_hash' => \secret_hash($submittedKey),
                'generated' => null,
            ];
        }

        if ($regenerate || ($previous['key_enc'] ?? '') === '') {
            $plainKey = bin2hex(random_bytes(32));
            return [
                'key_enc'  => \secret_encrypt($plainKey),
                'key_hash' => \secret_hash($plainKey),
                'generated' => $plainKey,
            ];
        }

        $keyEnc = (string) $previous['key_enc'];
        $keyHash = (string) ($previous['key_hash'] ?? '');
        if ($keyEnc !== '' && $keyHash === '') {
            $storedKey = \secret_decrypt($keyEnc);
            if ($storedKey !== null) {
                $keyHash = \secret_hash($storedKey);
            }
        }

        return [
            'key_enc'  => $keyEnc,
            'key_hash' => $keyHash,
            'generated' => null,
        ];
    }
}
