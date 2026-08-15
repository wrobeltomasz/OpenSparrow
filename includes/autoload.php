<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $rel  = substr($class, 4);
    $path = __DIR__ . '/../src/' . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
