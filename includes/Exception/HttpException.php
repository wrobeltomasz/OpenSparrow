<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Exception;

class HttpException extends \Exception implements ControlFlowException
{
    public function __construct(
        string $message = '',
        private int $statusCode = 500,
        private ?array $body = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromStatus(int $statusCode, string $message = '', ?array $body = null): self
    {
        return match ($statusCode) {
            400 => new BadRequestException($message, $body),
            401 => new UnauthorizedException($message, $body),
            403 => new ForbiddenException($message, $body),
            404 => new NotFoundException($message, $body),
            409 => new ConflictException($message, $body),
            500 => new ServerErrorException($message, $body),
            default => new self($message, $statusCode, $body),
        };
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function hasBody(): bool
    {
        return $this->body !== null;
    }

    public function body(): array
    {
        return $this->body ?? ['error' => $this->getMessage()];
    }

    public function withBody(array $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
}
