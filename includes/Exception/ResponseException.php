<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Exception;

final class ResponseException extends \Exception implements ControlFlowException
{
    private function __construct(
        private int $statusCode,
        private ?string $payload,
        private array $headers
    ) {
        parent::__construct('Response complete', $statusCode);
    }

    public static function json(array $data, int $statusCode = 200): self
    {
        return new self(
            $statusCode,
            (string) json_encode($data),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function encoded(mixed $data, int $flags = 0, int $statusCode = 0): self
    {
        return new self($statusCode, (string) json_encode($data, $flags), []);
    }

    public static function raw(string $payload, string $contentType = '', int $statusCode = 0): self
    {
        return new self(
            $statusCode,
            $payload,
            $contentType === '' ? [] : ['Content-Type' => $contentType]
        );
    }

    public static function sent(int $statusCode = 200): self
    {
        return new self($statusCode, null, []);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
