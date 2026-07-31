// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/dashboard/widgets/vertical-bar-chart.js — Registers the 'vertical_bar_chart' widget; delegates to _bar-chart-base renderBars.

import { WidgetRegistry } from '../registry.js';
import { renderBars } from './_bar-chart-base.js';

function renderVerticalBarChart(widget) {
    return renderBars(widget, 'vertical');
}

WidgetRegistry.register('vertical_bar_chart', renderVerticalBarChart);
export { renderVerticalBarChart };
