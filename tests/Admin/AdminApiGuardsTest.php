<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

final class AdminApiGuardsTest extends TestCase
{
    private const API_PHP     = __DIR__ . '/../../public/admin/api.php';
    private const MODULE_DIR  = __DIR__ . '/../../includes/admin';
    private const CSV_ENDPOINT = __DIR__ . '/../../public/admin/api_csv_import.php';

    private const MUTATING_ACTIONS = [
        'save', 'init_db',
        'users_add', 'users_toggle', 'users_update_role', 'users_update_contact',
        'users_change_password', 'user_policy_save',
        'user_tables_save',
        'create_table', 'add_column', 'schema_add_table',
        'run_cron_notifications', 'cron_purge_log',
        'backup_tables',
        'set_snapshot_setting', 'set_language_setting', 'set_chat_bubble_setting',
        'set_logo_enabled', 'set_app_name', 'upload_logo', 'remove_logo',
        'set_automation_email_setting', 'test_smtp_connection',
        'create_m2m', 'delete_m2m',
        'rag_upload', 'rag_delete', 'rag_rechunk', 'rag_rechunk_all',
        'rag_settings_save', 'rag_test_query', 'rag_ollama_check', 'rag_aggregate_view_save',
        'automations_save', 'automations_delete',
        'anonymization_save', 'run_anonymization', 'preview_anonymization', 'anonymization_purge_log',
        'etl_save', 'run_etl', 'etl_purge_log', 'etl_test_connection', 'etl_preview',
        'etl_flow_save', 'run_etl_flow', 'etl_flow_purge_log',
        'demo_install', 'demo_uninstall',
        'clickstats_save', 'clickstats_purge_log',
    ];

    private const DEMO_ALLOWED = [
        'preview_anonymization',
        'etl_test_connection',
        'etl_preview',
        'rag_test_query',
        'rag_ollama_check',
        'test_smtp_connection',
        'demo_install',
        'demo_uninstall',
    ];

    private static function apiSource(): string
    {
        return (string) file_get_contents(self::API_PHP);
    }

    private static function postActions(): array
    {
        preg_match('/\$postActions\s*=\s*\[(.*?)\];/s', self::apiSource(), $m);
        preg_match_all("/'([a-z0-9_]+)'/", $m[1] ?? '', $found);
        return $found[1];
    }

    private static function dispatchMap(): array
    {
        preg_match('/\$adminModules\s*=\s*\[(.*?)\];/s', self::apiSource(), $m);
        preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'([a-z0-9_]+)'/", $m[1] ?? '', $found, PREG_SET_ORDER);
        $map = [];
        foreach ($found as $pair) {
            $map[$pair[1]] = $pair[2];
        }
        return $map;
    }

    public function testEveryMutatingActionIsPostOnly(): void
    {
        $missing = array_diff(self::MUTATING_ACTIONS, self::postActions());
        $this->assertSame(
            [],
            array_values($missing),
            'Mutating action(s) absent from $postActions in public/admin/api.php. '
            . 'CSRF is only validated on POST/PATCH/DELETE, so these are reachable '
            . 'cross-site via GET: ' . implode(', ', $missing)
        );
    }

    public function testPostActionsContainsNoUnknownEntries(): void
    {
        $unknown = array_diff(self::postActions(), self::MUTATING_ACTIONS);
        $this->assertSame(
            [],
            array_values($unknown),
            'Entries in $postActions that this test does not know about. Either the '
            . 'action is mutating (add it to MUTATING_ACTIONS) or it is read-only '
            . 'and should not be POST-gated: ' . implode(', ', $unknown)
        );
    }

    public function testCsrfIsValidatedOnEveryMutatingVerb(): void
    {
        $this->assertMatchesRegularExpression(
            "/in_array\(\s*\\\$_SERVER\['REQUEST_METHOD'\],\s*\['POST',\s*'PATCH',\s*'DELETE'\]/",
            self::apiSource(),
            'public/admin/api.php must validate CSRF on POST, PATCH and DELETE '
            . '(mirroring os_api_bootstrap() in includes/bootstrap.php).'
        );
    }

    public function testFrontControllerSendsSecurityHeaders(): void
    {
        $src = self::apiSource();
        $this->assertStringContainsString('send_security_headers()', $src);
        $this->assertStringContainsString("ini_set('display_errors', '0')", $src);
    }

    public function testEveryMutatingActionGuardsDemoMode(): void
    {
        $map      = self::dispatchMap();
        $expected = array_diff(self::MUTATING_ACTIONS, self::DEMO_ALLOWED);
        $ungated  = [];
        $unlocated = [];

        foreach ($expected as $action) {
            $module = $map[$action] ?? null;
            if ($module === null) {
                continue;
            }
            $source = self::stripComments((string) file_get_contents(self::MODULE_DIR . '/' . $module . '.php'));
            $block  = self::actionBlock($source, $action);
            if ($block === null) {
                $unlocated[] = "{$action} (expected in {$module}.php)";
                continue;
            }
            if (!str_contains($block, 'require_not_demo') && !str_contains($block, 'DEMO_MODE')) {
                $ungated[] = "{$action} ({$module}.php)";
            }
        }

        $this->assertSame(
            [],
            $unlocated,
            'Could not locate the action block for mutating action(s), so their DEMO_MODE '
            . 'guard could not be checked at all. Fix the $adminModules mapping first — '
            . 'see AdminDispatchRegistryTest: ' . implode(', ', $unlocated)
        );

        $this->assertSame(
            [],
            $ungated,
            'Mutating action(s) with no DEMO_MODE guard: ' . implode(', ', $ungated)
        );
    }

    public function testCsvImportEndpointGuardsDemoMode(): void
    {
        $src = self::stripComments((string) file_get_contents(self::CSV_ENDPOINT));
        foreach (['csv_import_upload', 'csv_import_execute', 'csv_create_table'] as $action) {
            $block = self::actionBlock($src, $action);
            $this->assertNotNull($block, "Action block for {$action} not found.");
            $this->assertStringContainsString(
                'require_not_demo',
                $block,
                "{$action} writes to the database and must call require_not_demo()."
            );
        }
    }

    private static function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    private static function actionBlock(string $source, string $action): ?string
    {
        $start = strpos($source, "\$action === '{$action}'");
        if ($start === false) {
            return null;
        }
        $next = strpos($source, '$action ===', $start + 20);
        return $next === false
            ? substr($source, $start)
            : substr($source, $start, $next - $start);
    }
}
