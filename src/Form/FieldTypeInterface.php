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
    public function supports(ColumnConfig $col, bool $hasForeignKey): bool;

    /** Map raw POST data for one column to a typed, SQL-ready value. */
    public function bind(string $colName, array $postData): BoundValue;

    /** Render the HTML input widget for one column. */
    public function render(ColumnConfig $col, mixed $currentValue, RenderContext $ctx): string;
}
