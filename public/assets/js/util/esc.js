// assets/js/util/esc.js — escHtml(value): HTML-escapes & < > " ' (null/undefined → '').
// The single vetted escaping helper for innerHTML with dynamic data (see docs/SECURITY.md).
// Escapes both quote chars so output is safe inside attribute values too.
// Do not copy this helper into other files — import it (alias locally if needed).

export function escHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}
