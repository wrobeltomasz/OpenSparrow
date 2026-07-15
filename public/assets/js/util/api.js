// assets/js/util/api.js — apiFetch(url, options): shared fetch wrapper for API calls.
// Adds the X-CSRF-Token header (via getCsrfToken()) to every request. Bodies: FormData
// passes through untouched (browser sets the multipart Content-Type); anything else is
// sent as application/json — plain objects are JSON-encoded, strings are assumed to be
// pre-encoded JSON. Returns the raw Response — callers keep their own res.ok /
// data.error handling. Do not copy this helper into other files — import it.

import { getCsrfToken } from './csrf.js';

export function apiFetch(url, options = {}) {
    const { method = 'GET', body, headers = {}, ...rest } = options;
    const h = { 'X-CSRF-Token': getCsrfToken(), ...headers };
    let payload = body;
    if (body !== undefined && !(body instanceof FormData)) {
        h['Content-Type'] = 'application/json';
        if (typeof body !== 'string') payload = JSON.stringify(body);
    }
    return fetch(url, { method, headers: h, body: payload, ...rest });
}

// apiJson(url, options): apiFetch + JSON parse; throws Error(data.error ?? HTTP status)
// on failure. For callers that want parsed data and exception-style error handling.
export async function apiJson(url, options = {}) {
    const res  = await apiFetch(url, options);
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error ?? `HTTP ${res.status}`);
    return data;
}
