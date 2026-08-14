<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Keeps request_scope_inventory.php honest against the source.
 *
 * The per-user access boundary is enforced by remembering to call a gate at each
 * endpoint that accepts the name of a protected object. Every gap found in the
 * 2026-08 audit was a forgotten gate, not a broken helper — so the useful thing to
 * automate is not the gating itself but the remembering. This test scans for reads of
 * request-supplied table/view/print/board/workflow names and fails when one is not
 * written down, which makes a new endpoint impossible to merge without a decision
 * recorded next to it.
 *
 * What it cannot do: tell whether a gate is CORRECT, or whether 'gated' is true. It
 * checks that a file claiming to gate contains a gate call at all, and nothing more.
 * Treat a green run as "someone looked at every one of these", never as "these are
 * all safe" — a heuristic that felt complete would be worse than no test, because
 * then nobody would read the inventory either.
 */
final class RequestScopeInventoryTest extends TestCase
{
    /** Request keys that carry the name of an access-controlled object. */
    private const PROTECTED_KEYS = [
        'table', 'related_table', 'view', 'print', 'board', 'workflow', 'workflow_id',
    ];

    /** Superglobals and the conventional names for a decoded request body. */
    private const HOLDERS = ['_GET', '_POST', '_REQUEST', 'body', 'data', 'input', 'payload'];

    /**
     * Accessor methods that read the request without touching a superglobal.
     * src/Http/PhpRequest wraps $_GET/$_POST, and create.php and edit.php read their
     * ?table= exclusively through it — a scanner that only knew about superglobals
     * would report those two pages as taking no request-supplied name at all, which
     * is the one failure mode this whole test exists to prevent.
     */
    private const ACCESSORS = ['query', 'post', 'input', 'get'];

    private const DECISIONS = ['gated', 'scoped', 'admin', 'none'];

    /** Anything a gate call can look like, for the weak 'gated' cross-check. */
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

    /** @return array<string, array<string, string>> file => decision entries */
    private function inventory(): array
    {
        return require __DIR__ . '/request_scope_inventory.php';
    }

    /** Source files that can receive a request. */
    private function scannedFiles(): array
    {
        // public/admin/*.php belongs here even though every read it could hold would be
        // decided 'admin': the admin front controller and its two sibling endpoints are
        // reachable HTTP entry points, and leaving them out meant a ?table= added to one
        // of them would keep this test green. includes/admin/*.php was already scanned,
        // which is what made the omission a gap rather than a boundary.
        // includes/frontapi/*.php holds the route bodies of the frontend data API. They
        // are not HTTP entry points themselves, but they are where that API's
        // request-supplied ?table= / ?board= / workflow_id reads now live — the same
        // reason includes/admin/*.php is scanned. Omitting the directory would have let
        // the api.php split quietly move every one of those reads out of this inventory.
        $globs = [
            'public/*.php', 'public/api/*.php', 'public/admin/*.php',
            'includes/*.php', 'includes/admin/*.php', 'includes/frontapi/*.php',
            'templates/*.php',
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

    /** File contents with comments stripped — a commented-out read is not a read. */
    private function code(string $relPath): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::$root . '/' . $relPath)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /**
     * Every read of a protected key in the scanned sources.
     *
     * @return array<string, list<string>> file => ["_GET.table", "body.view", …]
     */
    private function scan(): array
    {
        $found = [];
        foreach ($this->scannedFiles() as $file) {
            $src = $this->code($file);
            foreach (self::PROTECTED_KEYS as $key) {
                $quoted = preg_quote($key, '/');

                foreach (self::HOLDERS as $holder) {
                    $pattern = '/\$' . preg_quote($holder, '/')
                        . '\s*\[\s*[\'"]' . $quoted . '[\'"]\s*\]/';
                    if (preg_match($pattern, $src) === 1) {
                        $found[$file][] = $holder . '.' . $key;
                    }
                }

                // ->query('table') and friends, on any object — matching the method
                // name rather than the variable keeps this working if the request
                // object is ever renamed or injected under a different name.
                foreach (self::ACCESSORS as $accessor) {
                    $pattern = '/->\s*' . preg_quote($accessor, '/')
                        . '\s*\(\s*[\'"]' . $quoted . '[\'"]/';
                    if (preg_match($pattern, $src) === 1) {
                        $found[$file][] = $accessor . '().' . $key;
                    }
                }
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
                    // Report the inventory key verbatim — it is what has to be added.
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

        // An inventory that outlives the code it describes stops being read, and then
        // it stops being true. Delete the entry with the endpoint.
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
                // A one-word reason is how an inventory turns into a checkbox.
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

            $src   = $this->code($file);
            $found = false;
            foreach (array_merge(self::GATE_CALLS, ['filter_by_user_access(']) as $call) {
                if (str_contains($src, $call)) {
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
        // The scanner is a regex over source text, so it is worth proving it matches
        // what it is supposed to. A silent scanner would make every other assertion
        // here pass for the wrong reason.
        $scan = $this->scan();
        $this->assertContains('_GET.table', $scan['public/api/fk.php'] ?? [], 'Scanner missed a $_GET read.');
        $this->assertContains('body.table', $scan['public/api/mass_edit.php'] ?? [], 'Scanner missed a $body read.');
        $this->assertContains('_POST.related_table', $scan['public/api/files.php'] ?? [], 'Scanner missed a $_POST read.');
        // The accessor form: edit.php reads its ?table= only through PhpRequest, so a
        // superglobal-only scanner would call this page clean while it is anything but.
        $this->assertContains('query().table', $scan['public/edit.php'] ?? [], 'Scanner missed a $request->query() read.');
        $this->assertNotEmpty($scan, 'Scanner found nothing at all — the globs are wrong.');
    }

    /**
     * Every directory that can receive an HTTP request is in range.
     *
     * scan() reports only files that CONTAIN a protected read, so a glob covering a
     * directory with none today looks identical to a glob that is missing — which is
     * how public/admin/ went unscanned. Assert the file list instead of the findings.
     */
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
