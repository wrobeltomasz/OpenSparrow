<?php

declare(strict_types=1);

namespace App\Http;

final class PhpRequest implements RequestInterface
{
    #[\Override]
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    #[\Override]
    public function query(string $key, string $default = ''): string
    {
        return (string)($_GET[$key] ?? $default);
    }

    #[\Override]
    public function post(string $key, string $default = ''): string
    {
        return (string)($_POST[$key] ?? $default);
    }

    #[\Override]
    public function postAll(): array
    {
        return $_POST;
    }

    #[\Override]
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }
}
