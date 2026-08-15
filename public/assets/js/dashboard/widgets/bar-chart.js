// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { WidgetRegistry } from '../registry.js';
import { renderBars } from './_bar-chart-base.js';

function renderBarChart(widget) {
    return renderBars(widget, 'horizontal');
}

WidgetRegistry.register('bar_chart', renderBarChart);
export { renderBarChart };
