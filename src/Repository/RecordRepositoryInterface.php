<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\TableConfig;
use App\Form\RecordData;

interface RecordRepositoryInterface
{
    public function find(TableConfig $config, string|int $id): ?array;
    public function update(TableConfig $config, string|int $id, RecordData $data): void;
    public function insert(TableConfig $config, RecordData $data): string|int;

    public function subtables(TableConfig $config, string|int $parentId): array;
}
