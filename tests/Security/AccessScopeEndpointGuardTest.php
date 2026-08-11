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
        $src = $this->code('public/api.php');

        $this->assertCodeHas(
            "defined('OS_FK_LABEL_COLUMNS')",
            $src,
            "api.php's list branch must narrow its projection when api/fk.php asks it to."
        );
        // Intersected, never assigned: the constant may only ever remove columns from
        // the schema-derived list, so it can never introduce a name of its own.
        $this->assertCodeHas(
            'array_intersect($selectCols, $keep)',
            $src,
            'The narrowed projection must be an intersection with the schema-derived columns.'
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
