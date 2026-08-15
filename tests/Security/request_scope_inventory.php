<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

return [

    'public/api.php' => [
        'body.table' => ['gated', 'Single require_table_access() in the shared write preamble, before any of the mutating routes (insert, update, delete, calendar move, board move, duplicate) is dispatched. The route modules deliberately do NOT repeat it — one gate, no per-route copies to forget — which tests/Security/FrontApiGuardsTest pins from both directions.'],
    ],
    'includes/frontapi/list.php' => [
        '_GET.table' => ['gated', 'Both routes (list, subtable_counts) call require_table_access() after resolving the table. The list route is the one exception in this API and it is explicit: api/fk.php delegates into it with OS_TABLE_ACCESS_DELEGATED for a schema-supplied reference table, and narrows the projection to label columns via OS_FK_LABEL_COLUMNS. That narrowing covers the filter_col allow-list as well as the SELECT list — a filter discloses what it matched without ever being selected, and filter_from/filter_to would otherwise turn the exemption into a range probe over any column of a table the user may not open.'],
    ],
    'includes/frontapi/m2m.php' => [
        '_GET.table' => ['gated', 'Both batch label lookups (m2m_rows, image_rows) call require_table_access() on the parent table once it is known to be configured. The row ids they take are request-supplied too, so each additionally drops ids the caller cannot see — filter_visible_ids() for image_rows, the owner_restriction_sql() clause keyed on the junction table\'s self_fk for m2m_rows — before disclosing any label, uuid or name for them.'],
    ],
    'includes/frontapi/board.php' => [
        '_GET.board' => ['scoped', 'The read route resolves ?board= against filter_by_user_access(boards, ...) and falls back to the first board of that filtered list, so an out-of-scope or unmatched id can never select a board the user was not granted. The table the resolved board is bound to is checked separately with user_can_access_table(), and an out-of-scope binding is blanked rather than named.'],
        'body.board' => ['scoped', 'move_card resolves the board id against filter_by_user_access(boards, ...); an unmatched id leaves $boardCfg empty and the request is rejected as an invalid board table. The record table itself was already gated by the write preamble in public/api.php — the board is a separate grant, which is why both apply.'],
    ],
    'includes/frontapi/workflow_procedure.php' => [
        'body.workflow_id' => ['gated', 'Calls require_access(workflows, ...) before looking the procedure up, then workflow_tables_in_scope() on the resolved entry. Both halves are needed: without the first the scope would be cosmetic (a direct POST would fire the procedure of a workflow hidden from the menu and the list), and without the second a workflow granted to someone whose tables do not cover its steps would still run against those tables.'],
    ],
    'public/api/clickstats.php' => [
        'input.table' => ['gated', 'Each buffered click may name the table that was in context. It is only ever written into spw_clickstats.table_name as a label - it selects nothing and reaches no identifier - but it is still checked with user_can_access(tables, ...) before being stored, so a user whose access is restricted cannot seed the admin-visible log with the names of tables they were never granted. A name outside the scope is stored as NULL rather than rejected: statistics must never fail a request. The check is not a validator: for an unrestricted user (user_allowed_items() returning null, the default) any string passes, so the value is truncated to the column width before it is stored and is treated as free text everywhere it is read back.'],
    ],
    'public/api/comments.php' => [
        '_GET.related_table'  => ['gated', 'Both read actions go through validatedTable(), which calls require_table_access() itself.'],
        'body.related_table'  => ['gated', 'The add action goes through validatedTable() as well.'],
    ],
    'public/api/data_cleanup.php' => [
        'body.table' => ['gated', 'validateInput() calls require_table_access() right after the unknown-table check.'],
    ],
    'public/api/files.php' => [
        '_GET.table'          => ['gated', 'files_action_get_related_records() calls require_table_access() on the requested table before resolving its relation config.'],
        'post().related_table' => ['gated', 'files_action_upload() gates it once for both upload paths (gallery and plain attachment), so a file cannot be attached to a record in a table the uploader has no access to.'],
    ],
    'public/api/fk.php' => [
        '_GET.table' => ['gated', 'The request-supplied SOURCE table is gated. The reference table it resolves to is schema-supplied and deliberately exempt, or FK dropdowns inside permitted tables would break; the projection is narrowed to the key and label columns to keep that exemption to labels.'],
    ],
    'public/api/mass_edit.php' => [
        'body.table' => ['gated', 'All three actions (mass edit, mass duplicate, mass delete) call require_table_access() after the unknown-table check.'],
    ],
    'public/api/notes.php' => [
        '_GET.table' => ['gated', 'The note list for a record goes through validatedTable(), which calls require_table_access() itself, so notes attached to a table outside the scope are unreachable.'],
    ],
    'public/api/owners.php' => [
        '_GET.table'  => ['gated', 'Both read actions go through validatedTable(). Note that reassigning an owner is open to editors by design — that is a separate decision, documented in docs/MAINTENANCE.md, and not an access-scope hole.'],
        'body.table'  => ['gated', 'Both write actions go through validatedTable().'],
    ],
    'public/api/print.php' => [
        '_GET.print' => ['gated', 'The list action skips printouts outside the scope; the data and param_options actions call require_print_access(). The list filter alone would not be enough — hiding a menu entry is not a boundary.'],
        '_GET.view'  => ['admin', 'The columns action is guarded by $role === admin: it serves the admin print-template editor, listing the live columns of a registered PostgreSQL view.'],
    ],
    'public/api/rag.php' => [
        'body.table' => ['gated', 'The query action calls require_table_access() when a table is given; the table drives the aggregate view fed into the model prompt, so without it the assistant would summarise rows the user cannot open.'],
    ],
    'public/api/views.php' => [
        '_GET.view' => ['gated', 'The list action skips views outside the scope; the data action calls require_view_access().'],
    ],
    'public/board.php' => [
        '_GET.board' => ['gated', 'os_require_access(boards, ...) redirects to the grid rather than rendering a shell whose data call comes back empty.'],
    ],
    'public/create.php' => [

        'query().table' => ['gated', 'os_require_table_access() runs right after the hasTable() check, before the form is built, so a table outside the scope never reaches the field rendering or the POST handler below it.'],
    ],
    'public/edit.php' => [
        'query().table' => ['gated', 'os_require_table_access() runs before the record lookup, so the table-level gate is applied ahead of the row-level ownership check. The subtable tabs are filtered separately further down, because a tab renders whole rows of the child table.'],
    ],
    'public/index.php' => [
        '_GET.table'    => ['gated', 'os_require_table_access() redirects to the default grid instead of rendering a page whose every XHR would 403.'],
        '_GET.workflow' => ['gated', 'os_require_access(workflows, ...) — the workflow wizard lives on this page rather than one of its own — followed by workflow_tables_in_scope() on the configured entry, so the page cannot render a shell that api=workflows refuses to fill. An id matching nothing configured falls through untouched.'],
    ],
    'public/print.php' => [
        '_GET.print' => ['gated', 'os_require_access(prints, ...) redirects to the grid, so a stale bookmark or a hand-edited URL cannot render a print shell whose data call would 403.'],
    ],
    'public/views.php' => [
        '_GET.view' => ['gated', 'os_require_access(views, ...) redirects to the grid, so a stale bookmark or a hand-edited URL cannot render a view shell whose data call would 403.'],
    ],
    'templates/menu.php' => [
        '_GET.table'    => ['none', 'Read only to mark the active menu entry, never to fetch anything. The items themselves come from filter_by_user_access(), so a name the user has no access to simply matches nothing and no entry lights up.'],
        '_GET.view'     => ['none', 'Active-entry highlight only, and the view list itself is already filtered — an out-of-scope name matches no entry.'],
        '_GET.print'    => ['none', 'Active-entry highlight only, and the printout list itself is already filtered — an out-of-scope name matches no entry.'],
        '_GET.board'    => ['none', 'Active-entry highlight only, and the board list itself is already filtered — an out-of-scope id matches no entry.'],
        '_GET.workflow' => ['none', 'Active-entry highlight only, and the workflow list itself is already filtered — an out-of-scope id matches no entry.'],
    ],
    'public/cypress_seed.php' => [
        'query().table' => ['none', 'Test-only endpoint, dead outside development: a hard APP_ENV === production guard returns 404 before anything else runs, and a shared-token check follows it. It seeds fixtures for the E2E suite and must be able to reach every table.'],
        'post().table'  => ['none', 'Same endpoint and the same two guards as above; the seeder writes fixture rows, so it must reach every table.'],
    ],
    'public/admin/api_csv_import.php' => [
        'body.table' => ['admin', 'Admin CSV import endpoint: os_api_bootstrap([role => admin]) refuses every other role with a 403 before the body is read, and admins are never restricted, so a per-user scope check would be a no-op by definition. The name is validated against the schema configuration before any DDL or INSERT, and identifiers reach SQL only through pg_ident(). Found by widening the scanner to public/admin/*.php — it was invisible while that glob was missing.'],
    ],
    'includes/admin/dashboard.php' => [
        'body.table' => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate. Admins are never restricted.'],
    ],
    'includes/admin/rag.php' => [
        'body.table' => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate; admins are never restricted.'],
        'body.view'  => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate; admins are never restricted.'],
    ],
    'includes/admin/schema.php' => [
        'query().table' => ['admin', 'Admin API module, behind the admin-role gate. This one edits the schema itself.'],
        'post().table'  => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate; admins are never restricted.'],
        'body.table'    => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate; admins are never restricted.'],
        'input.table'   => ['admin', 'Admin API module, included by public/admin/api.php only after the admin-role gate; admins are never restricted.'],
    ],
];
