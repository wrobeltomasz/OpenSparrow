<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function secret_encrypt(string $plaintext): string
{
    $key = hash('sha256', APP_ENCRYPTION_KEY, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Failed to encrypt secret.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function secret_decrypt(string $encoded): ?string
{
    if ($encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return null;
    }
    $key        = hash('sha256', APP_ENCRYPTION_KEY, true);
    $iv         = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext  = @openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}
