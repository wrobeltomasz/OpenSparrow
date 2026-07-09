// assets/js/util/csrf.js — getCsrfToken(): returns the session CSRF token.
// Sources, in order: window.CSRF_TOKEN (inlined by edit.php/files.php, which have no
// <meta> tag) then the <meta name="csrf-token"> tag (templates/layout.php pages).
// Single shared source for all fetch/AJAX mutations (X-CSRF-Token header or body token).
// Do not copy this helper into other files — import it (alias locally if needed).

export function getCsrfToken() {
    return window.CSRF_TOKEN
        ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '';
}
