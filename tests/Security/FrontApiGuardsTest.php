<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class FrontApiGuardsTest extends TestCase
{
    private const API_PHP    = 'includes/Controller/FrontApiController.php';
    private const MODULE_DIR = 'includes/frontapi';

    private const WRITE_MODULES = ['record.php', 'calendar.php', 'board.php'];

    private const READ_GATING_MODULES = ['list.php', 'm2m.php'];

    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    private function code(string $relativePath): string
    {
        $path = self::$root . '/' . $relativePath;
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

    private function moduleFiles(): array
    {
        $files = [];
        foreach (glob(self::$root . '/' . self::MODULE_DIR . '/*.php') ?: [] as $path) {
            $files[] = basename($path);
        }
        sort($files);
        return $files;
    }

    public function testFrontControllerGatesTheWriteTargetExactlyOnce(): void
    {
        $source   = $this->code(self::API_PHP);
        $count = substr_count($source, 'require_table_access(');

        $this->assertSame(
            1,
            $count,
            'includes/Controller/FrontApiController.php must call require_table_access() exactly once — the shared '
            . 'write preamble. Found ' . $count . '. More than one means the single gate '
            . 'has been split; none means the mutating routes are ungated.'
        );
    }

    public function testWriteGateRunsAfterTheTableIsResolvedAndBeforeDispatch(): void
    {
        $source = $this->code(self::API_PHP);

        $resolve  = strpos($source, 'safe_table($schema, $table)');
        $gate     = strpos($source, 'require_table_access($table)');
        $dispatch = strpos($source, '$osWriteRoutes');

        $this->assertIsInt($resolve, 'The write preamble no longer resolves $body[table] through safe_table().');
        $this->assertIsInt($gate, 'The write preamble no longer gates the resolved table.');
        $this->assertIsInt($dispatch, 'The write dispatch table is gone — check this test against the source.');

        $this->assertLessThan($gate, $resolve, 'The table must be resolved before it is gated.');
        $this->assertLessThan(
            $dispatch,
            $gate,
            'The write gate must run before any mutating route is dispatched, not after.'
        );
    }

    public function testWriteModulesDoNotRepeatTheTableGate(): void
    {
        foreach (self::WRITE_MODULES as $module) {
            $source = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertFalse(
                str_contains($source, 'require_table_access('),
                "includes/frontapi/{$module} must not call require_table_access(): the "
                . 'shared write preamble in includes/Controller/FrontApiController.php already gated '
                . '$context->table. A '
                . 'per-route copy is a list to keep by hand, which is how this class of '
                . 'gate has drifted before.'
            );
        }
    }

    public function testWriteHandlersTakeTheWriteContext(): void
    {
        foreach (self::WRITE_MODULES as $module) {
            $source = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertTrue(
                str_contains($source, 'FrontApiWriteContext $context'),
                "includes/frontapi/{$module} must receive a FrontApiWriteContext — "
                . 'constructing one is what proves the gate ran.'
            );
        }
    }

    public function testReadModulesGateTheirOwnRequestSuppliedTable(): void
    {
        foreach (self::READ_GATING_MODULES as $module) {
            $source = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertTrue(
                str_contains($source, 'require_table_access('),
                "includes/frontapi/{$module} reads a request-supplied ?table= and must "
                . 'gate it. See tests/Security/request_scope_inventory.php.'
            );
        }
    }

    public function testProfileActionsAnswerBeforeTheRoleGates(): void
    {
        $source = $this->code(self::API_PHP);

        $profile = strpos($source, "in_array(\$profileAction, ['update_avatar', 'change_password'], true)");
        $admin   = strpos($source, '$role === UserRole::Admin');
        $viewer  = strpos($source, '$role === UserRole::Viewer');

        $controller = 'includes/Controller/FrontApiController.php';

        $this->assertIsInt($profile, 'The self-service profile branch is gone from ' . $controller . '.');
        $this->assertIsInt($admin, 'The admin block is gone from ' . $controller . '.');
        $this->assertIsInt($viewer, 'The viewer read-only block is gone from ' . $controller . '.');

        $this->assertLessThan($admin, $profile, 'The profile actions must answer before the admin block.');
        $this->assertLessThan($viewer, $profile, 'The profile actions must answer before the viewer block.');
    }

    public function testRoleGatesRunBeforeTheSchemaIsLoaded(): void
    {
        $source = $this->code(self::API_PHP);

        $admin  = strpos($source, '$role === UserRole::Admin');
        $viewer = strpos($source, '$role === UserRole::Viewer');
        $schema = strpos($source, "config_get('schema')");

        $this->assertIsInt($schema, 'includes/Controller/FrontApiController.php no longer loads the schema.');
        $this->assertLessThan($schema, $admin, 'The admin block must run before the schema is read.');
        $this->assertLessThan($schema, $viewer, 'The viewer block must run before the schema is read.');
    }

    public function testOnlyTheFilteredSchemaIsHandedToTheContext(): void
    {
        $source = $this->code(self::API_PHP);

        $this->assertTrue(
            str_contains($source, 'filter_tables_for_user('),
            'includes/Controller/FrontApiController.php must build the access-filtered schema copy.'
        );
        $this->assertFalse(
            str_contains($source, 'echo json_encode($schema);'),
            'The schema route must never echo the unfiltered internal $schema.'
        );
    }

    private function referencedModules(): array
    {
        $source = $this->code(self::API_PHP);
        preg_match_all("/'([a-z_0-9]+)',\s*'frontapi_[a-z_0-9]+'/", $source, $write, PREG_SET_ORDER);
        preg_match_all("/=>\s*\['([a-z_0-9]+)',\s*'frontapi_[a-z_0-9]+'\]/", $source, $read, PREG_SET_ORDER);

        $modules = [];
        foreach (array_merge($read, $write) as $matches) {
            $modules[$matches[1] . '.php'] = true;
        }

        preg_match_all("/osFrontApiHandler\('([a-z_0-9]+)'/", $source, $direct, PREG_SET_ORDER);
        foreach ($direct as $matches) {
            $modules[$matches[1] . '.php'] = true;
        }

        $names = array_keys($modules);
        sort($names);
        return $names;
    }

    public function testEveryRoutedModuleExists(): void
    {
        $missing = array_values(array_diff($this->referencedModules(), $this->moduleFiles()));

        $this->assertSame(
            [],
            $missing,
            'includes/Controller/FrontApiController.php routes to module file(s) that do not exist under '
            . 'includes/frontapi/: ' . implode(', ', $missing)
        );
    }

    public function testEveryModuleIsReachable(): void
    {
        $routable = array_values(array_diff($this->moduleFiles(), ['context.php']));
        $orphans  = array_values(array_diff($routable, $this->referencedModules()));

        $this->assertSame(
            [],
            $orphans,
            'Module file(s) under includes/frontapi/ that no route in includes/Controller/FrontApiController.php '
            . 'reaches, so their handlers can never run: ' . implode(', ', $orphans)
        );
    }

    public function testReferencedModulesWereActuallyParsed(): void
    {
        $this->assertGreaterThanOrEqual(
            8,
            count($this->referencedModules()),
            'Could not parse the route tables out of includes/Controller/FrontApiController.php. Fix the patterns in '
            . 'this test — an unparsed route table makes the dispatch assertions vacuous.'
        );
    }
}
