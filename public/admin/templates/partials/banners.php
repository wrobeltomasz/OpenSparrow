<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

$firstRun ??= false;
?>
<div id="mig-pending-banner" class="mig-pending-banner">
    <strong>Upgrade notice:</strong> <span class="mig-pending-banner-text"></span>
</div>

<?php if ($firstRun) : ?>
    <div class="first-run-banner">
        <strong>First-run setup mode.</strong>
        Go to <strong>System &rarr; Database</strong> and click <strong>Initialize System Tables</strong>.
        This will create the default admin account (<code>admin</code> / <code>admin</code>).
        Afterwards <a href="../login.php">log in</a> and change the password immediately.
    </div>
<?php endif; ?>
