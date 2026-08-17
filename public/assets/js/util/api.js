// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { getCsrfToken } from './csrf.js';

export function apiFetch(url, options = {}) {
    const { method = 'GET', body, headers = {}, ...rest } = options;
    const requestHeaders = { 'X-CSRF-Token': getCsrfToken(), ...headers };
    let payload = body;
    if (body !== undefined && !(body instanceof FormData)) {
        requestHeaders['Content-Type'] = 'application/json';
        if (typeof body !== 'string') payload = JSON.stringify(body);
    }
    return fetch(url, { method, headers: requestHeaders, body: payload, ...rest });
}

export async function apiJson(url, options = {}) {
    const result  = await apiFetch(url, options);
    const data = await result.json();
    if (!result.ok || data.error) throw new Error(data.error ?? `HTTP ${result.status}`);
    return data;
}
