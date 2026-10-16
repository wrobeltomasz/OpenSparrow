<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/exception_handler.php';

use App\Exception\ForbiddenException;

if (php_sapi_name() !== 'cli') {
    os_register_exception_handler('html');
    throw new ForbiddenException('This script may only be run from the command line.');
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_implicit_flush(true);

function print_log(string $message): void
{
    echo $message . "\n";

    echo str_pad('', 4096) . "\n";
    flush();
}

require_once __DIR__ . '/../includes/cron_notifications.php';

$argv = (array) ($_SERVER['argv'] ?? []);
exit(cron_notifications_run($argv));
