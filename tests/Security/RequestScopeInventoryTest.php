<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class RequestScopeInventoryTest extends TestCase
{
    private const PROTECTED_KEYS = [
        'table', 'related_table', 'view', 'print', 'board', 'workflow', 'workflow_id',
    ];

    private const HOLDERS = ['_GET', '_POST', '_REQUEST', 'body', 'data', 'input', 'payload'];

    private const ACCESSORS = ['query', 'post', 'input', 'get'];

    private const DECISIONS = ['gated', 'scoped', 'admin', 'none'];

    private const GATE_CALLS = [
        'require_access(', 'require_table_access(', 'require_view_access(',
        'require_print_access(', 'os_require_access(', 'os_require_table_access(',
        'validatedTable(', 'user_can_access(', 'user_can_access_table(',
        'user_can_access_view(', 'user_can_access_print(',
    ];

    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    private function inventory(): array
    {
        return require __DIR__ . '/request_scope_inventory.php';
    }

    private function scannedFiles(): array
    {
        $globs = [
            'public/*.php', 'public/api/*.php', 'public/admin/*.php',
            'includes/*.php', 'includes/admin/*.php', 'includes/frontapi/*.php',
            'includes/Controller/*.php', 'templates/*.php',
        ];
        $files = [];
        foreach ($globs as $glob) {
            foreach (glob(self::$root . '/' . $glob) ?: [] as $path) {
                $files[] = str_replace('\\', '/', substr($path, strlen(self::$root) + 1));
            }
        }
        sort($files);
        return $files;
    }

    private function code(string $relPath): string
    {
        $output = '';
        foreach (token_get_all((string) file_get_contents(self::$root . '/' . $relPath)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }
        return $output;
    }

    private function scanSource(string $source): array
    {
        $found = [];
        foreach (self::PROTECTED_KEYS as $key) {
            $quoted = preg_quote($key, '/');

            foreach (self::HOLDERS as $holder) {
                $pattern = '/\$' . preg_quote($holder, '/')
                    . '\s*\[\s*[\'"]' . $quoted . '[\'"]\s*\]/';
                if (preg_match($pattern, $source) === 1) {
                    $found[] = $holder . '.' . $key;
                }
            }

            foreach (self::ACCESSORS as $accessor) {
                $pattern = '/->\s*' . preg_quote($accessor, '/')
                    . '\s*\(\s*[\'"]' . $quoted . '[\'"]/';
                if (preg_match($pattern, $source) === 1) {
                    $found[] = $accessor . '().' . $key;
                }
            }
        }
        return $found;
    }

    private function scan(): array
    {
        $found = [];
        foreach ($this->scannedFiles() as $file) {
            $reads = $this->scanSource($this->code($file));
            if ($reads !== []) {
                $found[$file] = $reads;
            }
        }
        return $found;
    }

    public function testEveryRequestSuppliedNameIsAccountedFor(): void
    {
        $inventory = $this->inventory();
        $missing   = [];

        foreach ($this->scan() as $file => $reads) {
            foreach ($reads as $read) {
                if (!isset($inventory[$file][$read])) {
                    $missing[] = $file . ' → ' . $read;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "This code reads a request-supplied table/view/print/board/workflow name that "
            . "nothing has decided about.\n\nAdd an entry to "
            . "tests/Security/request_scope_inventory.php saying whether it is gated, and why:\n  "
            . implode("\n  ", $missing) . "\n"
        );
    }

    public function testInventoryHasNoStaleEntries(): void
    {
        $scan  = $this->scan();
        $stale = [];

        foreach ($this->inventory() as $file => $entries) {
            foreach (array_keys($entries) as $read) {
                if (!in_array($read, $scan[$file] ?? [], true)) {
                    $stale[] = $file . ' → ' . $read;
                }
            }
        }

        $this->assertSame(
            [],
            $stale,
            "The inventory describes reads that no longer exist:\n  " . implode("\n  ", $stale) . "\n"
        );
    }

    public function testEveryEntryCarriesAValidDecisionAndAReason(): void
    {
        foreach ($this->inventory() as $file => $entries) {
            foreach ($entries as $read => $entry) {
                $where = $file . ' → ' . $read;

                $this->assertIsArray($entry, "{$where}: entry must be [decision, reason].");
                $this->assertCount(2, $entry, "{$where}: entry must be [decision, reason].");
                $this->assertContains(
                    $entry[0],
                    self::DECISIONS,
                    "{$where}: unknown decision '{$entry[0]}'."
                );

                $this->assertGreaterThan(
                    40,
                    strlen($entry[1]),
                    "{$where}: the reason must explain why this is safe, for someone who "
                    . "does not know the endpoint."
                );
            }
        }
    }

    public function testFilesClaimingToGateContainAGate(): void
    {
        foreach ($this->inventory() as $file => $entries) {
            $claimsGate = false;
            foreach ($entries as $entry) {
                if (in_array($entry[0], ['gated', 'scoped'], true)) {
                    $claimsGate = true;
                    break;
                }
            }
            if (!$claimsGate) {
                continue;
            }

            $source   = $this->code($file);
            $found = false;
            foreach (array_merge(self::GATE_CALLS, ['filter_by_user_access(']) as $call) {
                if (str_contains($source, $call)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "{$file} is recorded as gating or scoping a request-supplied name, but "
                . "contains no gate call at all. Either the gate was removed or the "
                . "inventory entry is wrong."
            );
        }
    }

    public function testScannerStillMatchesTheShapesItClaimsTo(): void
    {
        $shapes = $this->scanSource(
            '<?php $a = $_GET["table"]; $b = $_POST["related_table"]; $c = $body["view"];'
            . ' $d = $request->query("table"); $e = os_request()->post("board");'
        );
        $this->assertContains('_GET.table', $shapes, 'Scanner missed a $_GET read.');
        $this->assertContains('_POST.related_table', $shapes, 'Scanner missed a $_POST read.');
        $this->assertContains('body.view', $shapes, 'Scanner missed a $body read.');
        $this->assertContains('query().table', $shapes, 'Scanner missed a $request->query() read.');
        $this->assertContains('post().board', $shapes, 'Scanner missed a $request->post() read.');

        $scan = $this->scan();
        $this->assertContains('_GET.table', $scan['public/api/fk.php'] ?? [], 'Scanner missed a $_GET read.');
        $this->assertContains('body.table', $scan['public/api/mass_edit.php'] ?? [], 'Scanner missed a $body read.');
        $this->assertContains(
            'post().related_table',
            $scan['public/api/files.php'] ?? [],
            'Scanner missed a $request->post() read.'
        );

        $this->assertContains(
            'query().table',
            $scan['includes/Controller/EditController.php'] ?? [],
            'Scanner missed a $request->query() read.'
        );
        $this->assertNotEmpty($scan, 'Scanner found nothing at all — the globs are wrong.');
    }

    public function testEveryRequestReachableDirectoryIsScanned(): void
    {
        $files = $this->scannedFiles();

        $expected = [
            'public/index.php'            => 'the front controller',
            'public/api.php'              => 'the main API gateway',
            'public/api/files.php'        => 'the specialized endpoints',
            'public/admin/api.php'        => 'the admin front controller',
            'public/admin/api_migrations.php' => 'the admin sibling endpoints',
            'includes/api_helpers.php'    => 'the shared backend helpers',
            'includes/admin/schema.php'   => 'the admin API modules',
            'templates/menu.php'          => 'the FE templates',
        ];
        foreach ($expected as $file => $what) {
            $this->assertContains(
                $file,
                $files,
                "{$file} is not scanned, so {$what} could grow a request-supplied "
                . "table/view/print/board/workflow name without this test noticing."
            );
        }
    }
}
