<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Security;

enum UserRole: string
{
    case Admin  = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
    case Export = 'export';

    public static function fromSession(): self
    {
        return self::tryFrom($_SESSION['role'] ?? '') ?? self::Viewer;
    }
}
