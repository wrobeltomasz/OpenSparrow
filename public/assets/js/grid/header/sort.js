// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state, sortRows } from '../state.js';

export function toggleSortState(column) {
    if (state.sortState.column === column) {
        if (state.sortState.asc) {
            state.sortState = { column: column, asc: false };
        } else {
            state.sortState = { column: null, asc: true };
        }
    } else {
        state.sortState = { column: column, asc: true };
    }

    state.filteredData = state.sortState.column
        ? sortRows(state.unsortedFilteredData, state.sortState)
        : state.unsortedFilteredData.slice();
}
