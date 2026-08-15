<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

final class AdminDispatchRegistryTest extends TestCase
{
    private const API_PHP    = __DIR__ . '/../../public/admin/api.php';
    private const MODULE_DIR = __DIR__ . '/../../includes/admin';

    private const NON_ACTION_MODULES = ['helpers', 'etl_common'];

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
