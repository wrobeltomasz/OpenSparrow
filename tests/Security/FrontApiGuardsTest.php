<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards over the frontend data API front controller
 * (public/api.php) and its route modules under includes/frontapi/.
 *
 * The api.php split moved ~1300 lines out of the front controller. Three properties
 * of the original file were load-bearing for access control and none of them is
 * visible in any single module afterwards, which is exactly the kind of invariant a
 * later refactor drops without anything going red:
 *
 *  1. ONE write gate. All six mutating routes resolve their target from one
 *     $body['table'], so require_table_access() runs once, in the shared preamble.
 *     Pushing it into the modules would recreate a hand-kept list of "routes that
 *     remembered to gate" — the same shape as the admin $postActions whitelist and
 *     the per-action DEMO_MODE calls, both of which have silently drifted before.
 *  2. GATE ORDER. The self-service profile actions are permitted for every
 *     authenticated user and therefore run BEFORE the admin block and the viewer
 *     read-only block. The role gates in turn run before the schema is loaded and
 *     filtered. Reordering any of those changes who can reach what.
 *  3. DISPATCH COMPLETENESS. A route naming a module that does not exist, or a
 *     module no route reaches, is dead or broken code — the frontend equivalent of
 *     the $adminModules drift that AdminDispatchRegistryTest covers.
 *
 * Source-level assertions: the front controller needs a session, a database and a
 * live request, so none of this is reachable from a unit test. Comments are stripped
 * first — these passages are heavily commented, and a guard a comment could satisfy
 * is no guard.
 */
final class FrontApiGuardsTest extends TestCase
{
    private const API_PHP    = 'public/api.php';
    private const MODULE_DIR = 'includes/frontapi';

    /**
     * Route modules that run behind the shared write preamble. Their target table is
     * already resolved and gated by the time they are called, so none of them may
     * gate it again — nor skip it and gate something of its own.
     */
    private const WRITE_MODULES = ['record.php', 'calendar.php', 'board.php'];

    /** Modules that legitimately gate a request-supplied ?table= of their own. */
    private const READ_GATING_MODULES = ['list.php', 'm2m.php'];

    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /** File contents with comments removed and whitespace collapsed. */
    private function code(string $relPath): string
    {
        $path = self::$root . '/' . $relPath;
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

    /** @return list<string> module file names, e.g. ['board.php', …] */
    private function moduleFiles(): array
    {
        $files = [];
        foreach (glob(self::$root . '/' . self::MODULE_DIR . '/*.php') ?: [] as $path) {
            $files[] = basename($path);
        }
        sort($files);
        return $files;
    }

    // ── 1. One write gate ────────────────────────────────────────────────────────

    public function testFrontControllerGatesTheWriteTargetExactlyOnce(): void
    {
        $src   = $this->code(self::API_PHP);
        $count = substr_count($src, 'require_table_access(');

        $this->assertSame(
            1,
            $count,
            'public/api.php must call require_table_access() exactly once — the shared '
            . 'write preamble. Found ' . $count . '. More than one means the single gate '
            . 'has been split; none means the mutating routes are ungated.'
        );
    }

    public function testWriteGateRunsAfterTheTableIsResolvedAndBeforeDispatch(): void
    {
        $src = $this->code(self::API_PHP);

        $resolve  = strpos($src, 'safe_table($schema, $table)');
        $gate     = strpos($src, 'require_table_access($table)');
        $dispatch = strpos($src, '$osWriteRoutes');

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
            $src = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertFalse(
                str_contains($src, 'require_table_access('),
                "includes/frontapi/{$module} must not call require_table_access(): the "
                . 'shared write preamble in public/api.php already gated $ctx->table. A '
                . 'per-route copy is a list to keep by hand, which is how this class of '
                . 'gate has drifted before.'
            );
        }
    }

    /**
     * The write modules must take the context that only exists once the preamble ran.
     * A write handler accepting the plain read context would be reachable with an
     * unresolved, ungated table.
     */
    public function testWriteHandlersTakeTheWriteContext(): void
    {
        foreach (self::WRITE_MODULES as $module) {
            $src = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertTrue(
                str_contains($src, 'FrontApiWriteContext $ctx'),
                "includes/frontapi/{$module} must receive a FrontApiWriteContext — "
                . 'constructing one is what proves the gate ran.'
            );
        }
    }

    /**
     * Read routes that take their own request-supplied ?table= must still gate it
     * themselves: they run before the write preamble exists.
     */
    public function testReadModulesGateTheirOwnRequestSuppliedTable(): void
    {
        foreach (self::READ_GATING_MODULES as $module) {
            $src = $this->code(self::MODULE_DIR . '/' . $module);
            $this->assertTrue(
                str_contains($src, 'require_table_access('),
                "includes/frontapi/{$module} reads a request-supplied ?table= and must "
                . 'gate it. See tests/Security/request_scope_inventory.php.'
            );
        }
    }

    // ── 2. Gate order ───────────────────────────────────────────────────────────

    public function testProfileActionsAnswerBeforeTheRoleGates(): void
    {
        $src = $this->code(self::API_PHP);

        $profile = strpos($src, "in_array(\$profileAction, ['update_avatar', 'change_password'], true)");
        $admin   = strpos($src, '$role === UserRole::Admin');
        $viewer  = strpos($src, '$role === UserRole::Viewer');

        $this->assertIsInt($profile, 'The self-service profile branch is gone from public/api.php.');
        $this->assertIsInt($admin, 'The admin block is gone from public/api.php.');
        $this->assertIsInt($viewer, 'The viewer read-only block is gone from public/api.php.');

        // Deliberate: changing your own password must not depend on your role.
        $this->assertLessThan($admin, $profile, 'The profile actions must answer before the admin block.');
        $this->assertLessThan($viewer, $profile, 'The profile actions must answer before the viewer block.');
    }

    public function testRoleGatesRunBeforeTheSchemaIsLoaded(): void
    {
        $src = $this->code(self::API_PHP);

        $admin  = strpos($src, '$role === UserRole::Admin');
        $viewer = strpos($src, '$role === UserRole::Viewer');
        $schema = strpos($src, "config_get('schema')");

        $this->assertIsInt($schema, 'public/api.php no longer loads the schema.');
        $this->assertLessThan($schema, $admin, 'The admin block must run before the schema is read.');
        $this->assertLessThan($schema, $viewer, 'The viewer block must run before the schema is read.');
    }

    /**
     * Only the access-filtered document may be sent to a client. The unfiltered
     * $schema stays internal so config-supplied lookups keep resolving — see
     * AccessScopeEndpointGuardTest::testSchemaEndpointIsFilteredByTableAccess().
     */
    public function testOnlyTheFilteredSchemaIsHandedToTheContext(): void
    {
        $src = $this->code(self::API_PHP);

        $this->assertTrue(
            str_contains($src, 'filter_tables_for_user('),
            'public/api.php must build the access-filtered schema copy.'
        );
        $this->assertFalse(
            str_contains($src, 'echo json_encode($schema);'),
            'The schema route must never echo the unfiltered internal $schema.'
        );
    }

    // ── 3. Dispatch completeness ────────────────────────────────────────────────

    /** Module base names named anywhere in the front controller's route tables. */
    private function referencedModules(): array
    {
        $src = $this->code(self::API_PHP);
        preg_match_all("/'([a-z_0-9]+)',\s*'frontapi_[a-z_0-9]+'/", $src, $write, PREG_SET_ORDER);
        preg_match_all("/=>\s*\['([a-z_0-9]+)',\s*'frontapi_[a-z_0-9]+'\]/", $src, $read, PREG_SET_ORDER);

        $modules = [];
        foreach (array_merge($read, $write) as $m) {
            $modules[$m[1] . '.php'] = true;
        }
        // Reached by a direct call rather than a route table.
        preg_match_all("/osFrontApiHandler\('([a-z_0-9]+)'/", $src, $direct, PREG_SET_ORDER);
        foreach ($direct as $m) {
            $modules[$m[1] . '.php'] = true;
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
            'public/api.php routes to module file(s) that do not exist under '
            . 'includes/frontapi/: ' . implode(', ', $missing)
        );
    }

    public function testEveryModuleIsReachable(): void
    {
        // context.php holds the two context classes, not a route.
        $routable = array_values(array_diff($this->moduleFiles(), ['context.php']));
        $orphans  = array_values(array_diff($routable, $this->referencedModules()));

        $this->assertSame(
            [],
            $orphans,
            'Module file(s) under includes/frontapi/ that no route in public/api.php '
            . 'reaches, so their handlers can never run: ' . implode(', ', $orphans)
        );
    }

    public function testReferencedModulesWereActuallyParsed(): void
    {
        // Guards the regexes above: if the route tables are reformatted and stop
        // matching, every assertion in this section would pass on an empty list.
        $this->assertGreaterThanOrEqual(
            8,
            count($this->referencedModules()),
            'Could not parse the route tables out of public/api.php. Fix the patterns in '
            . 'this test — an unparsed route table makes the dispatch assertions vacuous.'
        );
    }
}
