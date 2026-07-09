<?php

declare(strict_types=1);

namespace App\Csrf;

use App\Http\SessionInterface;

final readonly class SessionCsrfTokenManager
{
    private const string KEY = 'csrf_token';

    public function __construct(private SessionInterface $session)
    {
    }

    public function token(): string
    {
        if (!$this->session->has(self::KEY)) {
            $this->session->set(self::KEY, bin2hex(random_bytes(32)));
        }
        return (string)$this->session->get(self::KEY);
    }

    public function isValid(string $given): bool
    {
        $stored = $this->session->get(self::KEY, '');
        return !empty($stored) && hash_equals((string)$stored, $given);
    }
}
