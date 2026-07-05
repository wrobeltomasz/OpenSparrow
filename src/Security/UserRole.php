<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// UserRole.php — Backed enum for the four application roles (admin, editor, viewer, export)
// Single source of truth for role names used in authorisation checks; sessions and
// the spw_users.role column keep storing the plain string value (->value)
// UserRole::fromSession() resolves the current session role, defaulting to Viewer
// Required explicitly by bootstrap.php and api_helpers.php (procedural entry points
// do not register the autoloader), and autoloadable as App\Security\UserRole in src/

declare(strict_types=1);

namespace App\Security;

enum UserRole: string
{
    case Admin  = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
    case Export = 'export';

    // Resolve the role of the currently logged-in user. Unknown or missing
    // values degrade to Viewer — the least privileged role (fail closed).
    public static function fromSession(): self
    {
        return self::tryFrom($_SESSION['role'] ?? '') ?? self::Viewer;
    }
}
