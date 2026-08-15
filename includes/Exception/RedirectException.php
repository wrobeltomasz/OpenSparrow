<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Exception;

final class RedirectException extends \Exception implements ControlFlowException
{
    public function __construct(private string $url, private int $statusCode = 302)
    {
        parent::__construct('Redirect to ' . $url, $statusCode);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
