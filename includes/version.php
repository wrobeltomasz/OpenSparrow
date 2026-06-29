<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// version.php — Defines OPENSPARROW_VERSION constant
// Single constant definition guarded to prevent redefinition; version string used for cache busting and release identification
// No logic, no security features

declare(strict_types=1);

if (!defined('OPENSPARROW_VERSION')) {
    define('OPENSPARROW_VERSION', '2.9');
}
