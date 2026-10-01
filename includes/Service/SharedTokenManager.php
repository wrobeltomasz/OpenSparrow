<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class SharedTokenManager
{
    public function generate(): array
    {
        $plainToken = bin2hex(random_bytes(32));
        return [
            'token'      => $plainToken,
            'token_hash' => \secret_hash($plainToken),
        ];
    }

    public function matches(array $entry, string $token): bool
    {
        $storedHash = (string) ($entry['token_hash'] ?? '');
        if ($storedHash === '') {
            return false;
        }
        return hash_equals($storedHash, \secret_hash($token));
    }
}
