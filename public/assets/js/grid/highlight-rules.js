// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// assets/js/grid/highlight-rules.js — Row-level conditional formatting engine.
// Config shape: table.highlight_rules = [{ column, op, value, color }, ...]
// Evaluated in order; the first matching rule's color wins (mirrors views.js applyColorRules,
// extended with string comparisons since grid columns are often text/enum, not just numeric).

function matchesRule(rawValue, op, ruleValue) {
    if (op === 'contains') {
        return String(rawValue ?? '').includes(String(ruleValue));
    }
    if (op === '==' || op === '!=') {
        const isEqual = String(rawValue ?? '') === String(ruleValue);
        return op === '==' ? isEqual : !isEqual;
    }

    const num = parseFloat(rawValue);
    const ruleNum = parseFloat(ruleValue);
    if (isNaN(num) || isNaN(ruleNum)) return false;
    if (op === '>') return num > ruleNum;
    if (op === '>=') return num >= ruleNum;
    if (op === '<') return num < ruleNum;
    if (op === '<=') return num <= ruleNum;
    return false;
}

export function matchHighlightRule(row, rules) {
    if (!Array.isArray(rules) || rules.length === 0) return null;
    for (const rule of rules) {
        if (!rule.column || !rule.op || !rule.color) continue;
        if (matchesRule(row[rule.column], rule.op, rule.value)) {
            return rule.color;
        }
    }
    return null;
}
