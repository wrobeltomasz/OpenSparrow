// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { state, sortRows } from '../state.js';

const MAX_SORT_RULES = 3;

export function toggleSortState(column) {
    const existingRule = state.sortState.find(rule => rule.column === column);

    if (existingRule) {
        if (existingRule.asc) {
            existingRule.asc = false;
        } else {
            state.sortState = state.sortState.filter(rule => rule.column !== column);
        }
    } else if (state.sortState.length < MAX_SORT_RULES) {
        state.sortState = [...state.sortState, { column: column, asc: true }];
    } else {
        state.sortState = [
            ...state.sortState.slice(0, MAX_SORT_RULES - 1),
            { column: column, asc: true },
        ];
    }

    state.filteredData = state.sortState.length > 0
        ? sortRows(state.unsortedFilteredData, state.sortState)
        : state.unsortedFilteredData.slice();
}