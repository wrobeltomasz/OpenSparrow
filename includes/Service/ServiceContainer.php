<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use PgSql\Connection;

final class ServiceContainer
{
    private ?RecordOwnershipService $ownership = null;

    private ?RecordSnapshotService $snapshots = null;

    private ?M2MService $m2m = null;

    private ?ImageService $images = null;

    private ?AutomationService $automations = null;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function connection(): Connection
    {
        return $this->conn;
    }

    public function ownership(): RecordOwnershipService
    {
        return $this->ownership ??= new RecordOwnershipService($this->conn);
    }

    public function snapshots(): RecordSnapshotService
    {
        return $this->snapshots ??= new RecordSnapshotService($this->conn);
    }

    public function m2m(): M2MService
    {
        return $this->m2m ??= new M2MService($this->conn);
    }

    public function images(): ImageService
    {
        return $this->images ??= new ImageService($this->conn);
    }

    public function automations(): AutomationService
    {
        return $this->automations ??= new AutomationService($this->conn);
    }
}
