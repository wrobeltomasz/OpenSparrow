<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Csrf;

use App\Csrf\SessionCsrfTokenManager;
use App\Http\SessionInterface;
use PHPUnit\Framework\TestCase;

final class SessionCsrfTokenManagerTest extends TestCase
{
    private function makeSession(array &$store): SessionInterface
    {
        return new class ($store) implements SessionInterface {
            public function __construct(private array &$store)
            {
            }
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }
            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }
            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
            public function userId(): int
            {
                return 0;
            }
            public function role(): string
            {
                return 'editor';
            }
        };
    }

    public function testTokenGeneratedOnFirstCall(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $tokenManager->token();
        $this->assertNotEmpty($token);
        $this->assertSame($token, $store['csrf_token']);
    }

    public function testTokenReusedOnSubsequentCalls(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $this->assertSame($tokenManager->token(), $tokenManager->token());
    }

    public function testIsValidReturnsTrueForCorrectToken(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $tokenManager->token();
        $this->assertTrue($tokenManager->isValid($token));
    }

    public function testIsValidReturnsFalseForWrongToken(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $tokenManager->token();
        $this->assertFalse($tokenManager->isValid('wrong_token'));
    }

    public function testIsValidReturnsFalseWhenNoTokenSet(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $this->assertFalse($tokenManager->isValid('anything'));
    }

    public function testTokenIsHexString(): void
    {
        $store = [];
        $tokenManager   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $tokenManager->token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }
}
