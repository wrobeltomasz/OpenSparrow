<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Exception;

final class ConflictException extends HttpException
{
    public function __construct(string $message = '', ?array $body = null, ?\Throwable $previous = null)
    {
        parent::__construct($message === '' ? 'Conflict.' : $message, 409, $body, $previous);
    }
}
