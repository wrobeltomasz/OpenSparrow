<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// crypto.php — Symmetric encryption for secrets stored at rest in spw_config
// (e.g. third-party API keys). AES-256-GCM via openssl, keyed by APP_ENCRYPTION_KEY
// (defined in config.php). Never used for passwords — those stay one-way (Argon2id).

declare(strict_types=1);

// Encrypts $plaintext for storage. Returns base64(iv . tag . ciphertext).
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

// Decrypts a value produced by secret_encrypt(). Returns null on any failure
// (corrupt data, wrong key, empty input) instead of throwing — callers treat
// null the same as "no secret configured".
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
