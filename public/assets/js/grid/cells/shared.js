// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { attachCellEvents } from '../../grid_actions.js';

export function createInputCell({ row, col, colCfg, isReadOnly, makeControl }) {
    const td = document.createElement('td');
    const control = makeControl();
    control.dataset.column = col;
    control.dataset.id = row['id'];

    if (colCfg.readonly || isReadOnly) control.disabled = true;
    if (!isReadOnly) attachCellEvents(control);

    td.appendChild(control);
    return td;
}
