<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\Controller\EditController;

$pageMeta = os_page_bootstrap(['csp' => 'unsafe-style', 'redirect_admin' => false]);

$controller = new EditController(os_boot_app());

$controller->handle($pageMeta);
