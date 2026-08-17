// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

function matchesRule(rawValue, op, ruleValue) {
    if (op === 'contains') {
        return String(rawValue ?? '').includes(String(ruleValue));
    }
    if (op === '==' || op === '!=') {
        const isEqual = String(rawValue ?? '') === String(ruleValue);
        return op === '==' ? isEqual : !isEqual;
    }

    const number = parseFloat(rawValue);
    const ruleNumber = parseFloat(ruleValue);
    if (isNaN(number) || isNaN(ruleNumber)) return false;
    if (op === '>') return number > ruleNumber;
    if (op === '>=') return number >= ruleNumber;
    if (op === '<') return number < ruleNumber;
    if (op === '<=') return number <= ruleNumber;
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
