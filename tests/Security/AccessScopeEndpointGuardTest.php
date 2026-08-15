<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class AccessScopeEndpointGuardTest extends TestCase
{
    private const LIST_MODULE     = 'includes/frontapi/list.php';
    private const BOARD_MODULE    = 'includes/frontapi/board.php';
    private const WORKFLOWS_MODULE = 'includes/frontapi/workflows.php';
    private const WF_PROC_MODULE  = 'includes/frontapi/workflow_procedure.php';

    private function code(string $relPath): string
    {
        $path = __DIR__ . '/../../' . $relPath;
        $this->assertFileExists($path);

        $output = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }

        return (string) preg_replace('/\s+/', ' ', $output);
    }

    private function assertCodeHas(string $needle, string $source, string $reason): void
    {
        $this->assertTrue(str_contains($source, $needle), $reason . ' Expected to find: ' . $needle);
    }

    public function testFkDelegationDefinesTheLabelColumnAllowList(): void
    {
        $source = $this->code('public/api/fk.php');

        $this->assertCodeHas(
            'if (!user_can_access_table($refTable))',
            $source,
            'api/fk.php must detect an out-of-scope reference table before delegating.'
        );
        $this->assertCodeHas(
            "define('OS_FK_LABEL_COLUMNS'",
            $source,
            'An out-of-scope reference table must be delegated with a narrowed projection.'
        );
    }

    public function testFkLabelAllowListNamesTheKeyAndTheDisplayColumns(): void
    {
        $source = $this->code('public/api/fk.php');
        $reason = 'The narrowed projection must cover what a dropdown consumes: the value '
            . 'it submits and the text it shows.';

        $this->assertCodeHas('reference_column', $source, $reason);
        $this->assertCodeHas('display_column', $source, $reason);
        $this->assertCodeHas('display_columns', $source, $reason);
    }

    public function testFkLabelAllowListIsDefinedBeforeTheGateIsWaived(): void
    {
        $source = $this->code('public/api/fk.php');

        $narrow = strpos($source, "define('OS_FK_LABEL_COLUMNS'");
        $waive  = strpos($source, "define('OS_TABLE_ACCESS_DELEGATED'");
        $this->assertIsInt($narrow, 'api/fk.php no longer narrows the FK projection.');
        $this->assertIsInt($waive, 'api/fk.php no longer marks the delegation.');

        $this->assertLessThan(
            $waive,
            $narrow,
            'The projection must be narrowed before the access gate is waived.'
        );
    }

    public function testListBranchHonoursTheFkLabelAllowList(): void
    {
        $source = $this->code(self::LIST_MODULE);

        $this->assertCodeHas(
            "defined('OS_FK_LABEL_COLUMNS')",
            $source,
            'The list route must narrow its projection when api/fk.php asks it to.'
        );

        $this->assertCodeHas(
            'array_intersect($selectColumns, $keep)',
            $source,
            'The narrowed projection must be an intersection with the schema-derived columns.'
        );
    }

    public function testFkLabelAllowListAlsoNarrowsTheFilterColumns(): void
    {
        $source = $this->code(self::LIST_MODULE);

        $this->assertCodeHas(
            'array_intersect($allowedFilterColumns, $selectColumns)',
            $source,
            'The FK label allow-list must narrow filter_col too, not the projection alone.'
        );

        $narrow = strpos($source, 'array_intersect($allowedFilterColumns, $selectColumns)');
        $test   = strpos($source, 'in_array($filterColumn, $allowedFilterColumns, true)');
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
        $source = $this->code('public/api/files.php');

        $this->assertCodeHas(
            'function assertFileAccess(',
            $source,
            'The shared file write gate must cover every attachment, not just galleries.'
        );
        $this->assertFalse(
            str_contains($source, 'AND related_field = $2 AND deleted_at IS NULL'),
            'The file write gate must not narrow itself back to gallery rows.'
        );
        $this->assertCodeHas(
            'user_can_access_table($relatedTable)',
            $source,
            'The file write gate must consult the per-user table scope, not ownership alone.'
        );
    }

    public function testFileListingIsFilteredByRecordOwnership(): void
    {
        $source = $this->code('public/api/files.php');

        $this->assertCodeHas(
            'ro.table_name = f.related_table AND ro.record_id = f.related_id',
            $source,
            'The file listing must drop attachments on records the caller does not own.'
        );

        $this->assertCodeHas(
            "!empty(\$tableConfig['owner_restricted'])",
            $source,
            'The listing predicate must be scoped to the owner-restricted tables.'
        );
    }

    public function testBoardBindingIsBlankedWhenOutOfScope(): void
    {
        $source = $this->code(self::BOARD_MODULE);

        $this->assertCodeHas(
            "\$meta['table'] = '';",
            $source,
            'An out-of-scope board must not disclose the table it is bound to.'
        );
        $this->assertCodeHas(
            "\$meta['status_column'] = '';",
            $source,
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
        $source = $this->code(self::WF_PROC_MODULE);

        $this->assertCodeHas(
            "require_access('workflows', \$workflowId)",
            $source,
            'workflow_procedure must gate the request-supplied workflow id.'
        );

        $this->assertCodeHas(
            'workflow_tables_in_scope($workflow)',
            $source,
            'workflow_procedure must also check the step tables, not just the id.'
        );
    }

    public function testWorkflowPageAppliesTheStepTableRule(): void
    {
        $this->assertCodeHas(
            'workflow_tables_in_scope($wfItem)',
            $this->code('public/index.php'),
            'index.php must apply the step-table rule, not the workflow id alone.'
        );
    }

    public function testBoardSelectionStartsFromTheFilteredList(): void
    {
        $source = $this->code(self::BOARD_MODULE);

        $this->assertCodeHas(
            "\$boards = filter_by_user_access('boards', \$boardsCfg['boards'] ?? [])",
            $source,
            'The board branch must resolve ?board= against the filtered list.'
        );
        $this->assertFalse(
            str_contains($source, "\$boardCfg = \$boardsCfg['boards'][0] ?? [];"),
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
        $javaScript = (string) file_get_contents(__DIR__ . '/../../public/admin/js/users.js');
        $this->assertFalse(
            str_contains($javaScript, 'const ACCESS_SCOPES'),
            'users.js must not re-declare the scope list; it renders data.scopes.'
        );
        $this->assertTrue(
            str_contains($javaScript, 'data.scopes'),
            'users.js must render the sections the server describes.'
        );
    }

    public function testAdminSaveKeepsAllDigitNamesAsStrings(): void
    {
        $source = $this->code('includes/admin/users.php');

        $this->assertCodeHas(
            "\$clean[\$scope] = array_map('strval', array_keys(\$seen));",
            $source,
            'The Access tab save must cast the collapsed names back to strings, or an '
            . 'all-digit table name is dropped and its scope silently becomes unrestricted.'
        );
    }

    public function testSchemaEndpointIsFilteredByTableAccess(): void
    {
        $source = $this->code('includes/Controller/FrontApiController.php');

        $this->assertCodeHas(
            "\$schemaPublic['tables'] = (object) filter_tables_for_user(",
            $source,
            'api.php?api=schema must not echo tables the user has no access to.'
        );

        $this->assertCodeHas(
            'throw ResponseException::raw((string) $schemaJson);',
            $source,
            'The schema branch must return the filtered document, not $schema itself.'
        );
        $this->assertFalse(
            str_contains($source, 'json_encode($schema)'),
            'The schema branch must not return the unfiltered internal $schema.'
        );
    }
}
