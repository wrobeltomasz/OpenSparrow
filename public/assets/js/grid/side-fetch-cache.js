// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const loadedSignatures = new Map();

export function pageSignature(kind, table, pageRows) {
    const ids = pageRows
        .map(row => row['id'])
        .filter(Boolean)
        .map(String)
        .sort((left, right) => left.localeCompare(right, undefined, { numeric: true }))
        .join(',');
    return `${table}:${kind}:${ids}`;
}

export function isPageLoaded(signature) {
    return loadedSignatures.has(signature);
}

export function markPageLoaded(signature) {
    loadedSignatures.set(signature, true);
}

export function clearLoadedPages(table = null) {
    if (table === null) {
        loadedSignatures.clear();
        return;
    }
    for (const key of loadedSignatures.keys()) {
        if (key.startsWith(`${table}:`)) loadedSignatures.delete(key);
    }
}