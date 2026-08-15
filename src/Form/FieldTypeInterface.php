<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form;

use App\Domain\Schema\ColumnConfig;

interface FieldTypeInterface
{
    public function supports(ColumnConfig $column, bool $hasForeignKey): bool;

    public function bind(string $colName, array $postData): BoundValue;

    public function render(ColumnConfig $column, mixed $currentValue, RenderContext $context): string;
}
