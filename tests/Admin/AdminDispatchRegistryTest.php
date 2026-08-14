<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Cross-checks the admin API dispatch registry against the modules it points at.
 *
 * The action name of every admin endpoint is written by hand in TWO places:
 *
 *   1. the $adminModules map in public/admin/api.php (action => module), which
 *      decides which file gets required, and
 *   2. the `if ($action === '<name>')` guard inside that module, which decides
 *      whether the block runs.
 *
 * Nothing connected the two, and the drift is silent in a way that also disables
 * an existing security guard. A map entry pointing at the wrong module (typo in
 * either half) means:
 *
 *   - the required module contains no matching guard, so nothing runs and the
 *     request falls through to demo/seed.php — the client gets the wrong
 *     response rather than an error, and
 *   - AdminApiGuardsTest::testEveryMutatingActionGuardsDemoMode() looks the
 *     action's block up through this same map, finds nothing, and (before the
 *     companion change to that test) skipped it. The DEMO_MODE assertion for
 *     that action passed vacuously.
 *
 * So a single typo could both break the action and silently drop its
 * demo-mode coverage. These tests fail the build instead.
 *
 * Like MigrationRegistryTest, this reads the source rather than executing it:
 * the registry and the guards live inside action blocks that need a session, a
 * database and the front controller's scope.
 */
final class AdminDispatchRegistryTest extends TestCase
{
    private const API_PHP    = __DIR__ . '/../../public/admin/api.php';
    private const MODULE_DIR = __DIR__ . '/../../includes/admin';

    /**
     * Helper files under includes/admin/ that are not action modules: they are
     * required by the front controller for their functions and legitimately
     * contain no `if ($action === …)` guard.
     */
    private const NON_ACTION_MODULES = ['helpers', 'etl_common'];

    /**
     * Parses the $adminModules action => module dispatch map out of the front
     * controller. Same literal AdminApiGuardsTest reads.
     *
     * @return array<string, string>
     */
    private static function dispatchMap(): array
    {
        $source = (string) file_get_contents(self::API_PHP);
        preg_match('/\$adminModules\s*=\s*\[(.*?)\n\];/s', $source, $m);
        preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'([a-z0-9_]+)'/", $m[1] ?? '', $found, PREG_SET_ORDER);

        $map = [];
        foreach ($found as $pair) {
            $map[$pair[1]] = $pair[2];
        }
        return $map;
    }

    /**
     * Every action name guarded by `if ($action === '<name>')` in a module,
     * keyed by module base name.
     *
     * Comments are stripped first. Without that a commented-out guard — or a
     * module that merely names an action in its header prose — would read as a
     * live guard, which is the same trap AdminApiGuardsTest::stripComments()
     * exists to close.
     *
     * An action may legitimately appear more than once in one module: config_files.php
     * guards 'menu_config' twice, splitting the GET and POST paths. The names are
     * de-duplicated here because this test is about which actions a module handles,
     * not how many branches it uses to do it.
     *
     * @return array<string, list<string>>
     */
    private static function moduleActions(): array
    {
        $actions = [];
        foreach (glob(self::MODULE_DIR . '/*.php') ?: [] as $path) {
            $module = basename($path, '.php');
            $source = self::stripComments((string) file_get_contents($path));
            preg_match_all("/\\\$action === '([a-z0-9_]+)'/", $source, $found);
            $actions[$module] = array_values(array_unique($found[1]));
        }
        return $actions;
    }

    /** Removes comments so prose and commented-out code cannot pose as a guard. */
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

    public function testDispatchMapParses(): void
    {
        $this->assertNotEmpty(
            self::dispatchMap(),
            'Could not parse the $adminModules map out of public/admin/api.php. '
            . 'If its formatting changed, fix the regex here — an unparsed map '
            . 'would make every other assertion in this file vacuous.'
        );
    }

    /**
     * The core invariant: an action is handled by the module the map sends it to,
     * not merely by some module somewhere.
     */
    public function testEveryMappedActionIsGuardedInItsOwnModule(): void
    {
        $moduleActions = self::moduleActions();
        $orphans       = [];

        foreach (self::dispatchMap() as $action => $module) {
            if (!in_array($action, $moduleActions[$module] ?? [], true)) {
                $located = [];
                foreach ($moduleActions as $candidate => $names) {
                    if (in_array($action, $names, true)) {
                        $located[] = $candidate . '.php';
                    }
                }
                $orphans[] = "'{$action}' => '{$module}' (guard found in: "
                    . ($located === [] ? 'no module at all' : implode(', ', $located)) . ')';
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "\$adminModules in public/admin/api.php maps action(s) to a module that does not "
            . "guard them. Each would fall through to demo/seed.php instead of running:\n  "
            . implode("\n  ", $orphans)
        );
    }

    /**
     * The reverse direction: an action block nobody can reach. The front
     * controller only requires a module when the map names it, so a guard whose
     * action is absent from the map is dead code — unless another action in the
     * same module happens to pull the file in, which makes reachability depend
     * on an unrelated entry and is worse than plainly dead.
     */
    public function testEveryGuardedActionIsInTheDispatchMap(): void
    {
        $map        = self::dispatchMap();
        $unreachable = [];

        foreach (self::moduleActions() as $module => $actions) {
            foreach ($actions as $action) {
                if (!isset($map[$action])) {
                    $unreachable[] = "{$module}.php: '{$action}'";
                }
            }
        }

        $this->assertSame(
            [],
            $unreachable,
            "Action guard(s) in includes/admin/ with no \$adminModules entry in "
            . "public/admin/api.php. The front controller never requires the module for "
            . "them, so the block cannot run:\n  " . implode("\n  ", $unreachable)
        );
    }

    public function testEveryMappedModuleFileExists(): void
    {
        $missing = [];
        foreach (array_unique(array_values(self::dispatchMap())) as $module) {
            $path = self::MODULE_DIR . '/' . $module . '.php';
            if (!is_file($path)) {
                $missing[] = $module . '.php';
            }
        }

        $this->assertSame(
            [],
            $missing,
            '$adminModules names module file(s) that do not exist — the front '
            . 'controller would emit a require warning and fall through: '
            . implode(', ', $missing)
        );
    }

    /**
     * Guards the helper-file list itself. A new action module that nobody wired
     * into the map would otherwise be indistinguishable from a helper.
     */
    public function testEveryModuleFileIsEitherMappedOrAKnownHelper(): void
    {
        $mapped   = array_unique(array_values(self::dispatchMap()));
        $unwired  = [];

        foreach (self::moduleActions() as $module => $actions) {
            if (in_array($module, self::NON_ACTION_MODULES, true)) {
                $this->assertSame(
                    [],
                    $actions,
                    "includes/admin/{$module}.php is listed as a non-action helper but "
                    . 'contains action guard(s). Either wire it into $adminModules or '
                    . 'remove it from NON_ACTION_MODULES.'
                );
                continue;
            }
            if (!in_array($module, $mapped, true)) {
                $unwired[] = $module . '.php';
            }
        }

        $this->assertSame(
            [],
            $unwired,
            'Module file(s) under includes/admin/ that no $adminModules entry points at '
            . 'and that are not known helpers: ' . implode(', ', $unwired)
        );
    }
}
