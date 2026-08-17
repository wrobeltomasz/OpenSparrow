// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getState } from './grid.js';
import { debugLog } from './debug.js';

export function exportCSV() {
  const { displayedColumns, filteredData } = getState();
  debugLog("Exporting CSV", { rows: filteredData.length });

  const header = displayedColumns.join(',');
  const rows = filteredData.map(dataRow =>
    displayedColumns.map(columnName => JSON.stringify(dataRow[columnName] ?? '')).join(',')
  );
  const csv = [header, ...rows].join('\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const downloadLink = document.createElement('a');
  downloadLink.href = url;
  downloadLink.download = 'export.csv';
  downloadLink.click();
  URL.revokeObjectURL(url);
}
