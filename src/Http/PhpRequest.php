<?php

declare(strict_types=1);

namespace App\Http;

final class PhpRequest
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function query(string $key, string $default = ''): string
    {
        return (string)($_GET[$key] ?? $default);
    }

    public function post(string $key, string $default = ''): string
    {
        return (string)($_POST[$key] ?? $default);
    }

    public function postAll(): array
    {
        return $_POST;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }
}
