<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Source guards for the two endpoints where the per-user table allow-list is
 * deliberately NOT enforced by a plain require_table_access() call, and where the
 * substitute is therefore easy to drop in a refactor without anything going red:
 *
 *  - api/fk.php delegates to api.php's list branch with the gate switched off, so a
 *    reference table outside the user's scope stays readable. That exemption is only
 *    defensible while the projection is narrowed to the key plus the label columns —
 *    without OS_FK_LABEL_COLUMNS it hands back every configured column of a table
 *    the user may not open, with search, filtering and pagination on top.
 *  - api.php?api=schema echoes the schema document itself. Its sibling
 *    public/api/schema.php filters by access; if this one stops doing so, the whole
 *    data model (table names, PG schema names, every column definition) is readable
 *    by any restricted user.
 *
 * Neither is reachable from a unit test — both need a session, a database and a
 * delegated require — so these assertions read the source instead. Comments are
 * stripped first via token_get_all(): a guard that a comment could satisfy is no
 * guard at all, and the passages below are heavily commented.
 *
 * Assertions go through assertTrue(str_contains(...)) rather than
 * assertStringContainsString(): the haystack here is a whole endpoint, and a failure
 * message that dumps it is unreadable. The explanatory message is the useful part.
 */
final class AccessScopeEndpointGuardTest extends TestCase
{
    // public/api.php was split into a front controller plus one module per route group
    // under includes/frontapi/ (see docs/MAINTENANCE.md). The assertions below follow
    // the code: each one reads the file that now holds the guard it pins. The front
    // controller keeps only what it still owns — the ?api=schema echo and the single
    // write gate.
    private const LIST_MODULE     = 'includes/frontapi/list.php';
    private const BOARD_MODULE    = 'includes/frontapi/board.php';
    private const WORKFLOWS_MODULE = 'includes/frontapi/workflows.php';
    private const WF_PROC_MODULE  = 'includes/frontapi/workflow_procedure.php';

    /** Source of a repo file with all comments removed and whitespace collapsed. */
    private function code(string $relPath): string
    {
        $path = __DIR__ . '/../../' . $relPath;
        $this->assertFileExists($path);

        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return (string) preg_replace('/\s+/', ' ', $out);
    }

    private function assertCodeHas(string $needle, string $src, string $why): void
    {
        $this->assertTrue(str_contains($src, $needle), $why . ' Expected to find: ' . $needle);
    }

    public function testFkDelegationDefinesTheLabelColumnAllowList(): void
    {
        $src = $this->code('public/api/fk.php');

        $this->assertCodeHas(
            'if (!user_can_access_table($refTable))',
            $src,
            'api/fk.php must detect an out-of-scope reference table before delegating.'
        );
        $this->assertCodeHas(
            "define('OS_FK_LABEL_COLUMNS'",
            $src,
            'An out-of-scope reference table must be delegated with a narrowed projection.'
        );
    }

    public function testFkLabelAllowListNamesTheKeyAndTheDisplayColumns(): void
    {
        $src = $this->code('public/api/fk.php');
        $why = 'The narrowed projection must cover what a dropdown consumes: the value '
            . 'it submits and the text it shows.';

        // display_columns is the plural spelling the workflow wizard writes; both shapes
        // appear in stored schemas, so both have to be collected.
        $this->assertCodeHas('reference_column', $src, $why);
        $this->assertCodeHas('display_column', $src, $why);
        $this->assertCodeHas('display_columns', $src, $why);
    }

    public function testFkLabelAllowListIsDefinedBeforeTheGateIsWaived(): void
    {
        $src = $this->code('public/api/fk.php');

        $narrow = strpos($src, "define('OS_FK_LABEL_COLUMNS'");
        $waive  = strpos($src, "define('OS_TABLE_ACCESS_DELEGATED'");
        $this->assertIsInt($narrow, 'api/fk.php no longer narrows the FK projection.');
        $this->assertIsInt($waive, 'api/fk.php no longer marks the delegation.');
        // Both are constants: whichever is defined second cannot influence the first,
        // and api.php reads them together. Ordering keeps the pair readable and makes
        // an early exit between them impossible to introduce unnoticed.
        $this->assertLessThan(
            $waive,
            $narrow,
            'The projection must be narrowed before the access gate is waived.'
        );
    }

    public function testListBranchHonoursTheFkLabelAllowList(): void
    {
        $src = $this->code(self::LIST_MODULE);

        $this->assertCodeHas(
            "defined('OS_FK_LABEL_COLUMNS')",
            $src,
            'The list route must narrow its projection when api/fk.php asks it to.'
        );
        // Intersected, never assigned: the constant may only ever remove columns from
        // the schema-derived list, so it can never introduce a name of its own.
        $this->assertCodeHas(
            'array_intersect($selectCols, $keep)',
            $src,
            'The narrowed projection must be an intersection with the schema-derived columns.'
        );
    }

    /**
     * Narrowing the projection is only half of the FK exemption. filter_col is checked
     * against the table's OWN column list, so while that check stayed at the full list a
     * restricted user could filter an out-of-scope reference table on a column the
     * response never shows — and filter_from/filter_to make it a range probe, so the
     * value gets binary-searched out of which rows come back, keyed to the label that
     * does show. A filter never has to be selected to disclose what it matched.
     */
    public function testFkLabelAllowListAlsoNarrowsTheFilterColumns(): void
    {
        $src = $this->code(self::LIST_MODULE);

        $this->assertCodeHas(
            'array_intersect($allowedFilterCols, $selectCols)',
            $src,
            'The FK label allow-list must narrow filter_col too, not the projection alone.'
        );

        // Order matters as much as presence: narrowing after the membership test would
        // read as a guard and do nothing. Pinned by position for that reason.
        $narrow = strpos($src, 'array_intersect($allowedFilterCols, $selectCols)');
        $test   = strpos($src, 'in_array($filterCol, $allowedFilterCols, true)');
        $this->assertIsInt($narrow, 'The list route no longer narrows the FK filter columns.');
        $this->assertIsInt($test, 'The list route no longer validates filter_col against a list.');
        $this->assertLessThan(
            $test,
            $narrow,
            'filter_col must be narrowed before it is tested for membership, not after.'
        );
    }

    public function testFileWriteGateCoversEveryAttachment(): void
    {
        $src = $this->code('public/api/files.php');

        // The gate used to select `related_field = IMAGES_FIELD`, which left plain
        // attachments unchecked on delete, mass-delete, mass-tag and metadata edit.
        // Re-adding that predicate to this query silently reopens it.
        $this->assertCodeHas(
            'function assertFileAccess(',
            $src,
            'The shared file write gate must cover every attachment, not just galleries.'
        );
        $this->assertFalse(
            str_contains($src, 'AND related_field = $2 AND deleted_at IS NULL'),
            'The file write gate must not narrow itself back to gallery rows.'
        );
        $this->assertCodeHas(
            'user_can_access_table($rTable)',
            $src,
            'The file write gate must consult the per-user table scope, not ownership alone.'
        );
    }

    /**
     * Read half of the same policy. The write gate above covers delete, mass-delete,
     * mass-tag and metadata edit, and file_download.php covers the bytes — but the
     * LISTING selects from spw_files directly, so none of that touches it, and it used
     * to hand out the name, tags, uploader and related_id of attachments on rows the
     * caller does not own.
     *
     * Correlated on f.related_table rather than parameterised like owner_restriction_sql(),
     * because one page of this listing spans many tables. Both halves are pinned so a
     * refactor cannot quietly drop one and leave the other looking complete.
     */
    public function testFileListingIsFilteredByRecordOwnership(): void
    {
        $src = $this->code('public/api/files.php');

        $this->assertCodeHas(
            'ro.table_name = f.related_table AND ro.record_id = f.related_id',
            $src,
            'The file listing must drop attachments on records the caller does not own.'
        );
        // Scoped to owner_restricted tables: applied to every related_table it would
        // start hiding files on open tables the moment somebody assigned an owner.
        $this->assertCodeHas(
            "!empty(\$tCfg['owner_restricted'])",
            $src,
            'The listing predicate must be scoped to the owner-restricted tables.'
        );
    }

    public function testBoardBindingIsBlankedWhenOutOfScope(): void
    {
        $src = $this->code(self::BOARD_MODULE);

        // The board's table and status column are schema metadata; an out-of-scope
        // board must answer like an unconfigured one, not name its binding.
        $this->assertCodeHas(
            "\$meta['table'] = '';",
            $src,
            'An out-of-scope board must not disclose the table it is bound to.'
        );
        $this->assertCodeHas(
            "\$meta['status_column'] = '';",
            $src,
            'An out-of-scope board must not disclose its status column.'
        );
    }

    public function testWorkflowListIsFilteredByStepTables(): void
    {
        $this->assertCodeHas(
            'workflow_tables_in_scope',
            $this->code(self::WORKFLOWS_MODULE),
            'Workflows whose steps land outside the user\'s tables must not be listed.'
        );
        $this->assertCodeHas(
            'workflow_tables_in_scope($wfItem)',
            $this->code('templates/menu.php'),
            'The workflow submenu must apply the same step-table rule as the endpoint.'
        );
    }

    /**
     * The step-table rule has four call sites and only one implementation. It used to
     * be written out inline, which is how the two DISPLAY sites got it and the one
     * that FIRES a workflow did not — so pin the predicate itself, not a copy of it.
     */
    public function testStepTableRuleHasASingleImplementation(): void
    {
        $this->assertCodeHas(
            'function workflow_tables_in_scope(',
            $this->code('includes/api_helpers.php'),
            'The step-table rule must live in one place.'
        );

        $callSites = [
            'public/api.php',
            self::WORKFLOWS_MODULE,
            self::WF_PROC_MODULE,
            'templates/menu.php',
            'public/index.php',
        ];
        foreach ($callSites as $file) {
            $this->assertFalse(
                str_contains($this->code($file), 'user_can_access_table($stepTable)'),
                "{$file} must call workflow_tables_in_scope(), not re-implement it inline."
            );
        }
    }

    public function testWorkflowProcedureIsGatedByWorkflowScope(): void
    {
        $src = $this->code(self::WF_PROC_MODULE);

        // The scope is cosmetic without this one: hiding a workflow from the menu and
        // the list still leaves a direct POST able to fire its procedure.
        $this->assertCodeHas(
            "require_access('workflows', \$workflowId)",
            $src,
            'workflow_procedure must gate the request-supplied workflow id.'
        );
        // …and gating the id alone is not enough: granting a workflow does not grant
        // the tables its steps write to, so the endpoint that RUNS one must apply the
        // same step-table rule the list and the menu apply when they DISPLAY it.
        $this->assertCodeHas(
            'workflow_tables_in_scope($wf)',
            $src,
            'workflow_procedure must also check the step tables, not just the id.'
        );
    }

    public function testWorkflowPageAppliesTheStepTableRule(): void
    {
        // The wizard is hosted by index.php while its data comes from api=workflows.
        // If the page rendered a shell the endpoint refuses to fill, the user would be
        // left on a form with nothing to submit to.
        $this->assertCodeHas(
            'workflow_tables_in_scope($wfItem)',
            $this->code('public/index.php'),
            'index.php must apply the step-table rule, not the workflow id alone.'
        );
    }

    public function testBoardSelectionStartsFromTheFilteredList(): void
    {
        $src = $this->code(self::BOARD_MODULE);

        // The branch falls back to "the first board" when ?board= is missing or does
        // not match. Filtering after that fallback would hand a restricted user a
        // board they were never granted, so the list has to be narrowed first.
        $this->assertCodeHas(
            "\$boards = filter_by_user_access('boards', \$boardsCfg['boards'] ?? [])",
            $src,
            'The board branch must resolve ?board= against the filtered list.'
        );
        $this->assertFalse(
            str_contains($src, "\$boardCfg = \$boardsCfg['boards'][0] ?? [];"),
            'The board fallback must not reach past the filter into the raw config.'
        );
    }

    public function testPageGatesCoverBoardsAndWorkflows(): void
    {
        $this->assertCodeHas(
            "os_require_access('boards', \$boardId)",
            $this->code('public/board.php'),
            'board.php must gate ?board= like views.php gates ?view=.'
        );
        $this->assertCodeHas(
            "os_require_access('workflows', \$requestedWorkflow)",
            $this->code('public/index.php'),
            'index.php must gate ?workflow= — the wizard lives on that page.'
        );
    }

    public function testAdminAccessTabKeepsNoScopeListOfItsOwn(): void
    {
        // The Access tab renders whatever user_tables_get sends, and that comes from
        // USER_ACCESS_SCOPES. A local list here is how the picker and the gates drift.
        $js = (string) file_get_contents(__DIR__ . '/../../public/admin/js/users.js');
        $this->assertFalse(
            str_contains($js, 'const ACCESS_SCOPES'),
            'users.js must not re-declare the scope list; it renders data.scopes.'
        );
        $this->assertTrue(
            str_contains($js, 'data.scopes'),
            'users.js must render the sections the server describes.'
        );
    }

    /**
     * The save path keeps the ticked names as ARRAY KEYS to collapse duplicates, and PHP
     * casts an all-digit string key to an int on the way in. Left uncast, a table named
     * "2024" comes back from array_keys() as int 2024, merge_user_access_selection()
     * drops it on its is_string filter, and a scope left with nothing means UNRESTRICTED
     * — the one grant an admin made would widen access instead of narrowing it. That is
     * the only place in the access code where the digit-key trap fails OPEN, which is
     * why it is pinned rather than left to review.
     *
     * Not reachable from a unit test: the block runs at include time inside the admin
     * front controller's action dispatch, so there is no function to call.
     */
    public function testAdminSaveKeepsAllDigitNamesAsStrings(): void
    {
        $src = $this->code('includes/admin/users.php');

        $this->assertCodeHas(
            "\$clean[\$scope] = array_map('strval', array_keys(\$seen));",
            $src,
            'The Access tab save must cast the collapsed names back to strings, or an '
            . 'all-digit table name is dropped and its scope silently becomes unrestricted.'
        );
    }

    public function testSchemaEndpointIsFilteredByTableAccess(): void
    {
        $src = $this->code('public/api.php');

        $this->assertCodeHas(
            "\$schemaPublic['tables'] = (object) filter_tables_for_user(",
            $src,
            'api.php?api=schema must not echo tables the user has no access to.'
        );
        // The internal $schema stays unfiltered on purpose — every config-supplied
        // lookup in this file (FK references, subtables, board and calendar bindings)
        // reads it and must keep resolving.
        $this->assertCodeHas(
            'echo $schemaJson;',
            $src,
            'The schema branch must echo the filtered document, not $schema itself.'
        );
        $this->assertFalse(
            str_contains($src, 'echo json_encode($schema);'),
            'The schema branch must not echo the unfiltered internal $schema.'
        );
    }
}
