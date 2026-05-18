<?php

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
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $mgr->token();
        $this->assertNotEmpty($token);
        $this->assertSame($token, $store['csrf_token']);
    }

    public function testTokenReusedOnSubsequentCalls(): void
    {
        $store = [];
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $this->assertSame($mgr->token(), $mgr->token());
    }

    public function testIsValidReturnsTrueForCorrectToken(): void
    {
        $store = [];
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $mgr->token();
        $this->assertTrue($mgr->isValid($token));
    }

    public function testIsValidReturnsFalseForWrongToken(): void
    {
        $store = [];
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $mgr->token();
        $this->assertFalse($mgr->isValid('wrong_token'));
    }

    public function testIsValidReturnsFalseWhenNoTokenSet(): void
    {
        $store = [];
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $this->assertFalse($mgr->isValid('anything'));
    }

    public function testTokenIsHexString(): void
    {
        $store = [];
        $mgr   = new SessionCsrfTokenManager($this->makeSession($store));
        $token = $mgr->token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }
}
