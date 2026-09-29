// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { computeVirtual } from './cells/virtual-cell.js';

export function applyVirtualColumn(schema, table, row) {
    const tableColumns = schema.tables[table]?.columns || {};
    let touched = false;
    for (const [columnName, columnConfig] of Object.entries(tableColumns)) {
        if (columnConfig.type !== 'virtual') continue;
        row[columnName] = computeVirtual(columnConfig.formula, row);
        touched = true;
    }
    return touched;
}