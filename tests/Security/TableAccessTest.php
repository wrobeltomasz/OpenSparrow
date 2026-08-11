<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-user allow-lists in includes/api_helpers.php, covering all
 * three scopes: tables, views and printouts.
 *
 * The decision these helpers make is an access decision, so the edge cases matter
 * more than the happy path. In particular the "empty list means UNRESTRICTED"
 * contract is deliberate and load-bearing: it is what keeps every pre-existing
 * user working after the feature ships. If someone ever flips that to "empty means
 * no access" without migrating the stored config first, the whole frontend goes
 * dark for everyone — these tests pin the contract down.
 *
 * The config store is seeded through config_cache() rather than a database: the
 * helpers read via config_get(), which consults that cache first.
 *
 * Every test uses a distinct user id on purpose. user_allowed_items() memoises per
 * id for the request, so reusing an id across tests would silently assert against
 * the previous test's fixture.
 */
final class TableAccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/api_helpers.php';
        require_once __DIR__ . '/../../includes/config_store.php';
    }

    protected function setUp(): void
    {
        $_SESSION['role'] = 'editor';
        // The closure and the pick lists read config documents, so a fixture from one
        // test would otherwise decide another test's answer depending on execution
        // order. Start every test from empty documents; those that need content seed
        // their own. Driven off the registry so a new scope is reset automatically.
        $this->seedSchema([]);
        foreach (USER_ACCESS_SCOPES as $def) {
            $this->seedConfig($def['config'], [$def['path'] => []]);
        }
    }

    /**
     * Replace the whole user_table_access document.
     *
     * @param array<string, array<string, list<string>>|list<string>> $users
     */
    private function seed(array $users): void
    {
        config_cache('user_table_access', ['value' => ['users' => $users], 'version' => 1], true);
    }

    /** Shorthand for the common "tables only" fixture. */
    private function seedTables(string $userId, array $tables): void
    {
        $this->seed([$userId => ['tables' => $tables]]);
    }

    public function testListedTablesAreTheOnlyOnesAllowed(): void
    {
        $this->seedTables('101', ['orders', 'clients']);
        $_SESSION['user_id'] = 101;

        $this->assertSame(['orders', 'clients'], user_allowed_tables());
        $this->assertTrue(user_can_access_table('orders'));
        $this->assertTrue(user_can_access_table('clients'));
        $this->assertFalse(user_can_access_table('invoices'));
    }

    public function testUserWithNoEntryIsUnrestricted(): void
    {
        $this->seedTables('999', ['orders']);
        $_SESSION['user_id'] = 102;

        $this->assertNull(user_allowed_tables(), 'No entry must mean unrestricted, not denied.');
        $this->assertTrue(user_can_access_table('anything'));
    }

    public function testEmptyListMeansUnrestrictedNotDenied(): void
    {
        $this->seedTables('103', []);
        $_SESSION['user_id'] = 103;

        $this->assertNull(user_allowed_tables());
        $this->assertTrue(
            user_can_access_table('orders'),
            'An empty list is "no restriction configured" — denying here would lock out every '
            . 'user the moment an admin cleared their checkboxes.'
        );
    }

    public function testAdminRoleIsNeverRestricted(): void
    {
        $this->seedTables('104', ['orders']);
        $_SESSION['user_id'] = 104;
        $_SESSION['role']    = 'admin';

        $this->assertNull(user_allowed_tables());
        $this->assertTrue(user_can_access_table('invoices'));
    }

    public function testAdminShortcutDoesNotApplyToAnotherUser(): void
    {
        // The shortcut answers "is the CALLER an admin". Asked about somebody else —
        // a "what does this user see" preview, a per-user notification job — the
        // caller's role says nothing about the subject, and reading it anyway would
        // report every user as unrestricted from the admin panel.
        $this->seedTables('124', ['orders']);
        $_SESSION['user_id'] = 999;
        $_SESSION['role']    = 'admin';

        $this->assertSame(['orders'], user_allowed_tables(124));
        $this->assertFalse(user_can_access_table('invoices', 124));
        // The caller's own answer is unaffected.
        $this->assertNull(user_allowed_tables());
    }

    public function testNoSessionIsUnrestricted(): void
    {
        $this->seedTables('105', ['orders']);
        unset($_SESSION['user_id']);

        $this->assertNull(
            user_allowed_tables(),
            'Cron and CLI contexts have no session and must not be filtered.'
        );
    }

    public function testNonStringEntriesAreDiscarded(): void
    {
        // A hand-edited or half-migrated config must not turn into a table named "1".
        $this->seedTables('106', ['orders', 42, null, 'clients']);
        $_SESSION['user_id'] = 106;

        $this->assertSame(['orders', 'clients'], user_allowed_tables());
    }

    public function testDuplicatesCollapse(): void
    {
        $this->seedTables('107', ['orders', 'orders', 'clients']);
        $_SESSION['user_id'] = 107;

        $this->assertSame(['orders', 'clients'], user_allowed_tables());
    }

    public function testFilterTablesKeepsOnlyAllowedAndPreservesOrder(): void
    {
        $this->seedTables('108', ['clients', 'orders']);
        $_SESSION['user_id'] = 108;

        $tables = ['orders' => 1, 'invoices' => 2, 'clients' => 3];

        $this->assertSame(
            ['orders', 'clients'],
            array_keys(filter_tables_for_user($tables)),
            'Filtering must preserve the schema order, not the allow-list order — the menu '
            . 'renders in schema order.'
        );
    }

    public function testFilterTablesIsIdentityWhenUnrestricted(): void
    {
        $this->seed([]);
        $_SESSION['user_id'] = 109;

        $tables = ['orders' => 1, 'invoices' => 2];
        $this->assertSame($tables, filter_tables_for_user($tables));
    }

    public function testAllowListEntryForAnotherUserDoesNotLeak(): void
    {
        $this->seed(['110' => ['tables' => ['orders']], '111' => ['tables' => ['invoices']]]);

        $_SESSION['user_id'] = 110;
        $this->assertTrue(user_can_access_table('orders'));
        $this->assertFalse(user_can_access_table('invoices'));

        // Explicit id argument, not the session — used by admin-side callers.
        $this->assertTrue(user_can_access_table('invoices', 111));
        $this->assertFalse(user_can_access_table('orders', 111));
    }

    // ── Views and printouts ─────────────────────────────────────────────────

    public function testViewsAndPrintsAreGrantedByName(): void
    {
        $this->seed(['112' => [
            'tables' => ['orders'],
            'views'  => ['v_sales'],
            'prints' => ['invoice_pdf'],
        ]]);
        $_SESSION['user_id'] = 112;

        $this->assertTrue(user_can_access_view('v_sales'));
        $this->assertFalse(user_can_access_view('v_payroll'));
        $this->assertTrue(user_can_access_print('invoice_pdf'));
        $this->assertFalse(user_can_access_print('payslip_pdf'));
    }

    public function testScopesAreIndependent(): void
    {
        // Tables restricted, views and printouts left alone — the most common real
        // configuration, and the one most easily broken by a shared code path.
        $this->seed(['113' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 113;

        $this->assertFalse(user_can_access_table('invoices'));
        $this->assertNull(user_allowed_items('views'));
        $this->assertTrue(user_can_access_view('any_view'));
        $this->assertTrue(user_can_access_print('any_print'));
    }

    public function testRestrictingViewsLeavesTablesUnrestricted(): void
    {
        $this->seed(['114' => ['views' => ['v_sales']]]);
        $_SESSION['user_id'] = 114;

        $this->assertTrue(user_can_access_table('invoices'));
        $this->assertFalse(user_can_access_view('v_payroll'));
    }

    public function testBareListIsReadAsTablesOnly(): void
    {
        // The pre-scopes document shape. A config written before views and printouts
        // existed must keep restricting tables — and must NOT be read as an empty
        // (= unrestricted) tables list, which would silently widen access on upgrade.
        $this->seed(['115' => ['orders', 'clients']]);
        $_SESSION['user_id'] = 115;

        $this->assertSame(['orders', 'clients'], user_allowed_tables());
        $this->assertFalse(user_can_access_table('invoices'));
        $this->assertTrue(user_can_access_view('any_view'), 'Legacy shape grants no view restriction.');
    }

    public function testUnknownScopeThrows(): void
    {
        // A typo in a scope name must be a hard error, never a silent "unrestricted".
        $this->seed(['116' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 116;

        $this->expectException(\InvalidArgumentException::class);
        user_allowed_items('gadgets');
    }

    public function testFilterByUserAccessNarrowsViews(): void
    {
        $this->seed(['117' => ['views' => ['v_sales']]]);
        $_SESSION['user_id'] = 117;

        $views = ['v_payroll' => 1, 'v_sales' => 2];
        $this->assertSame(['v_sales'], array_keys(filter_by_user_access('views', $views)));
    }

    // ── The scope registry ───────────────────────────────────────────────────
    // Scopes come in two config shapes: name-keyed maps (tables, views, printouts)
    // and lists of objects identified by a field (boards, workflows). Every helper
    // has to handle both without its callers knowing which is which — that is the
    // whole point of the registry, and these tests pin it.

    /** Replace one config document. */
    private function seedConfig(string $key, array $value): void
    {
        config_cache($key, ['value' => $value, 'version' => 1], true);
    }

    public function testScopeItemsNormaliseBothConfigShapes(): void
    {
        $this->seedConfig('views', ['views' => ['v_sales' => ['display_name' => 'Sales']]]);
        $this->seedConfig('board', ['boards' => [['id' => 'brd_1', 'menu_name' => 'Sales Board']]]);

        // A map yields its keys, a list yields its id field — same shape out.
        $this->assertSame(['v_sales' => 'Sales'], access_scope_items('views'));
        $this->assertSame(['brd_1' => 'Sales Board'], access_scope_items('boards'));
    }

    public function testScopeItemsSkipHiddenAndUnidentifiableEntries(): void
    {
        $this->seedConfig('print', ['prints' => [
            'inv'    => ['display_name' => 'Invoice'],
            'draft'  => ['display_name' => 'Draft', 'hidden' => true],
        ]]);
        $this->seedConfig('workflows', ['workflows' => [
            ['id' => 'wf_1', 'title' => 'Onboarding'],
            ['title' => 'No id at all'],
            'not-an-array',
        ]]);

        $this->assertSame(['inv' => 'Invoice'], access_scope_items('prints'));
        $this->assertSame(['wf_1' => 'Onboarding'], access_scope_items('workflows'));
    }

    public function testScopeItemsFallBackToTheKeyWhenUnlabelled(): void
    {
        $this->seedConfig('views', ['views' => ['v_raw' => []]]);
        $this->assertSame(['v_raw' => 'v_raw'], access_scope_items('views'));
    }

    public function testEveryScopeCarriesTheKeysItsConsumersRead(): void
    {
        // A row added to the registry with a field missing would surface as an undefined
        // index deep inside the admin tab or a 403 message, far from the typo.
        foreach (USER_ACCESS_SCOPES as $scope => $def) {
            foreach (['config', 'path', 'id', 'label', 'noun', 'plural', 'title', 'empty'] as $field) {
                $this->assertArrayHasKey($field, $def, "Scope '{$scope}' is missing '{$field}'.");
            }
            // Singular for the 403, plural for the tab's count badge — conflating them
            // is how "Restricted to 2 of 5 table" gets shipped.
            $this->assertNotSame(
                $def['noun'],
                $def['plural'],
                "Scope '{$scope}' must spell its singular and plural nouns separately."
            );
        }
    }

    public function testUnknownScopeDefinitionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        access_scope('gadgets');
    }

    public function testBoardsAndWorkflowsAreGrantedById(): void
    {
        $this->seed(['125' => ['boards' => ['brd_1'], 'workflows' => ['wf_1']]]);
        $_SESSION['user_id'] = 125;

        $this->assertTrue(user_can_access('boards', 'brd_1'));
        $this->assertFalse(user_can_access('boards', 'brd_2'));
        $this->assertTrue(user_can_access('workflows', 'wf_1'));
        $this->assertFalse(user_can_access('workflows', 'wf_2'));
        // Independent of every other scope, as always.
        $this->assertNull(user_allowed_items('tables'));
    }

    public function testFilterKeepsListsAsListsAndMapsAsMaps(): void
    {
        $this->seed(['126' => ['boards' => ['brd_2'], 'views' => ['v_sales']]]);
        $_SESSION['user_id'] = 126;

        $boards = [
            ['id' => 'brd_1', 'menu_name' => 'One'],
            ['id' => 'brd_2', 'menu_name' => 'Two'],
        ];
        $filtered = filter_by_user_access('boards', $boards);
        $this->assertTrue(array_is_list($filtered), 'A list scope must come back as a list.');
        $this->assertSame([['id' => 'brd_2', 'menu_name' => 'Two']], $filtered);

        $views = ['v_payroll' => 1, 'v_sales' => 2];
        $this->assertSame(['v_sales' => 2], filter_by_user_access('views', $views));
    }

    public function testFilterDropsListEntriesWithoutAnId(): void
    {
        $this->seed(['127' => ['workflows' => ['wf_1']]]);
        $_SESSION['user_id'] = 127;

        $items = [['id' => 'wf_1'], ['title' => 'no id'], 'not-an-array'];
        $this->assertSame([['id' => 'wf_1']], filter_by_user_access('workflows', $items));
    }

    public function testUnrestrictedScopeReturnsTheInputUntouched(): void
    {
        $this->seed(['128' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 128;

        $boards = [['id' => 'brd_1'], ['id' => 'brd_2']];
        $this->assertSame($boards, filter_by_user_access('boards', $boards));
    }

    // ── Default-on gating at the API boundary ────────────────────────────────
    // os_request_scope_violation() is the rule os_api_bootstrap() applies to every API
    // request. The two are separate so the rule can be tested at all: the gate itself
    // ends the process.

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    public function testBoundaryGateRefusesAnOutOfScopeName(): void
    {
        $this->seedConfig('views', ['views' => ['v_ok' => [], 'v_secret' => []]]);
        $this->seed(['129' => ['views' => ['v_ok']]]);
        $_SESSION['user_id'] = 129;

        $_GET['view'] = 'v_secret';
        $this->assertSame(['views', 'v_secret'], os_request_scope_violation());

        $_GET['view'] = 'v_ok';
        $this->assertNull(os_request_scope_violation());
    }

    public function testBoundaryGateReadsGetPostAndBody(): void
    {
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'secret' => []]]);
        $this->seed(['130' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 130;

        $_GET['table'] = 'secret';
        $this->assertSame(['tables', 'secret'], os_request_scope_violation());
        $_GET = [];

        $_POST['related_table'] = 'secret';
        $this->assertSame(['tables', 'secret'], os_request_scope_violation());
        $_POST = [];

        $this->assertSame(['tables', 'secret'], os_request_scope_violation(['table' => 'secret']));
    }

    public function testBoundaryGateIgnoresUnknownNames(): void
    {
        // An unknown name must fall through so the endpoint can answer 400/404 in its
        // own words. Turning a typo into 403 would collapse the "unknown is 400, not
        // yours is 403" distinction the endpoints and their tests rely on.
        $this->seedConfig('schema', ['tables' => ['orders' => []]]);
        $this->seed(['131' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 131;

        $_GET['table'] = 'no_such_table';
        $this->assertNull(os_request_scope_violation());
    }

    public function testBoundaryGateStillSeesHiddenEntries(): void
    {
        // Hidden means unreachable-by-menu, not unknown. Treating it as unknown here
        // would let a hidden table through the boundary gate ungated.
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'ukryta' => ['hidden' => true]]]);
        $this->seed(['132' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 132;

        $_GET['table'] = 'ukryta';
        $this->assertSame(['tables', 'ukryta'], os_request_scope_violation());
    }

    // ── Saving a selection ───────────────────────────────────────────────────
    // A save replaces the user's whole entry, so what happens to a scope the payload
    // does not mention is a security decision, not a detail: [] means UNRESTRICTED.

    public function testOmittedScopeKeepsItsStoredValue(): void
    {
        $stored = ['tables' => ['orders'], 'views' => ['v_ok'], 'boards' => ['brd_1']];

        // A caller that only knows about tables must not widen views and boards.
        $merged = merge_user_access_selection(['tables' => ['clients']], $stored);

        $this->assertSame(['clients'], $merged['tables'], 'The submitted scope must win.');
        $this->assertSame(['v_ok'], $merged['views'], 'An omitted scope must not be widened.');
        $this->assertSame(['brd_1'], $merged['boards'], 'An omitted scope must not be widened.');
        $this->assertSame([], $merged['prints'], 'Nothing stored and nothing sent stays unrestricted.');
    }

    public function testExplicitEmptyListStillClearsAScope(): void
    {
        // This is how the Access tab removes a restriction — it must keep working, or
        // an admin could never hand access back.
        $merged = merge_user_access_selection(
            ['views' => []],
            ['tables' => ['orders'], 'views' => ['v_ok']]
        );

        $this->assertSame([], $merged['views'], 'An explicit [] must clear the scope.');
        $this->assertSame(['orders'], $merged['tables']);
    }

    public function testMalformedScopeValueDoesNotWiden(): void
    {
        // Not a list = not an instruction to clear. Malformed input must never resolve
        // in the widening direction.
        foreach (['orders', 42, null, false] as $junk) {
            $merged = merge_user_access_selection(['tables' => $junk], ['tables' => ['orders']]);
            $this->assertSame(
                ['orders'],
                $merged['tables'],
                'A malformed submitted value must fall back to the stored list.'
            );
        }
    }

    public function testMergeCoversEveryRegisteredScope(): void
    {
        // The stored document is written from this result, so a scope missing from it
        // would be dropped from the entry on the next save.
        $merged = merge_user_access_selection([], []);

        $this->assertSame(array_keys(USER_ACCESS_SCOPES), array_keys($merged));
    }

    public function testBodyIsNotReadWhenThereCannotBeOne(): void
    {
        // Both branches short-circuit before touching php://input. GET/HEAD carry no
        // body; multipart/form-data has already been consumed by PHP while filling
        // $_POST/$_FILES, so the stream is empty and reading it would prove nothing.
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $ctype  = $_SERVER['CONTENT_TYPE'] ?? null;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            unset($_SERVER['CONTENT_TYPE']);
            $this->assertSame([], os_gate_request_body());

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_TYPE']   = 'multipart/form-data; boundary=----x';
            $this->assertSame([], os_gate_request_body());
        } finally {
            // Restore exactly, including "was not set at all" — a leftover
            // REQUEST_METHOD would decide a later test's answer.
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
            if ($ctype === null) {
                unset($_SERVER['CONTENT_TYPE']);
            } else {
                $_SERVER['CONTENT_TYPE'] = $ctype;
            }
        }
    }

    public function testBodyParsingIsNotKeyedOnTheDeclaredContentType(): void
    {
        // Reading the body only for application/json put the gate under the CLIENT's
        // control: a POST labelled text/plain, or carrying no Content-Type at all,
        // sailed past untouched while the endpoint parsed the same bytes as JSON. The
        // per-endpoint gates meant it was never an open door, but "closed by default"
        // has to hold whatever the request claims to be sending.
        //
        // Comments are stripped first — the explanation above this function in the
        // source names the old content type, and matching that would make this pass
        // for the wrong reason.
        $src = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../../includes/api_helpers.php')) as $t) {
            $src .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
        }

        $this->assertStringNotContainsString(
            "\$_SERVER['CONTENT_TYPE'] ?? '', 'application/json'",
            $src,
            'The gate must not decide whether to read the body from the declared content type.'
        );
        $this->assertStringContainsString(
            "'multipart/form-data'",
            $src,
            'multipart is the one content type the gate must skip — PHP already consumed that stream.'
        );
    }

    public function testBoundaryGateWalksArrayValuedParameters(): void
    {
        // ?table[]=secret is a shape the CLIENT picks, and a bare is_string() test used
        // to skip it. Skipping is the one outcome a gate must never have for something
        // the caller controls — the endpoint would still resolve the value its own way.
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'secret' => []]]);
        $this->seed(['136' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 136;

        $_GET['table'] = ['secret'];
        $this->assertSame(['tables', 'secret'], os_request_scope_violation());

        $_GET['table'] = ['orders', 'secret'];
        $this->assertSame(
            ['tables', 'secret'],
            os_request_scope_violation(),
            'An allowed name earlier in the list must not shadow a refused one.'
        );

        $_GET['table'] = ['orders'];
        $this->assertNull(os_request_scope_violation());
    }

    public function testBoundaryGateIgnoresNonStringNoise(): void
    {
        // Ints, nulls, nested arrays and objects reach no endpoint as a table name —
        // they must not throw here either, and must not be mistaken for a violation.
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'secret' => []]]);
        $this->seed(['137' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 137;

        foreach ([42, null, '', ['secret' => 'x'], [['secret']], [null, 7]] as $noise) {
            $_GET['table'] = $noise;
            $this->assertNull(
                os_request_scope_violation(),
                'Non-string parameter shapes must fall through, not error or refuse.'
            );
        }
    }

    public function testBoundaryGateHonoursPerParameterOverrides(): void
    {
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'secret' => []]]);
        $this->seed(['133' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 133;

        $_GET['table'] = 'secret';
        $this->assertNull(os_request_scope_violation([], ['table' => false]));
        $this->assertSame(['tables', 'secret'], os_request_scope_violation([], ['view' => false]));
    }

    public function testBoundaryGateLeavesUnrestrictedUsersAlone(): void
    {
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'secret' => []]]);
        $this->seed(['134' => ['views' => ['v_ok']]]);
        $_SESSION['user_id'] = 134;

        $_GET['table'] = 'secret';
        $this->assertNull(os_request_scope_violation());
    }

    // ── Hidden-subtable closure ──────────────────────────────────────────────
    // A hidden table cannot be ticked in the admin picker, so its access has to
    // follow its parent's or it is unreachable for every restricted user — see
    // with_hidden_subtables(). These tests pin both halves of that rule: hidden
    // children are pulled in, visible ones stay a deliberate admin choice.

    /** Replace the schema document. Only the keys the closure reads are needed. */
    private function seedSchema(array $tables): void
    {
        config_cache('schema', ['value' => ['tables' => $tables], 'version' => 1], true);
    }

    public function testHiddenSubtableOfGrantedTableIsAccessible(): void
    {
        $this->seedSchema([
            'zadania'    => ['subtables' => [['table' => 'checklisty'], ['table' => 'komentarze']]],
            'checklisty' => ['hidden' => true],
            'komentarze' => [],
        ]);
        $this->seedTables('118', ['zadania']);
        $_SESSION['user_id'] = 118;

        $this->assertTrue(
            user_can_access_table('checklisty'),
            'A hidden subtable must follow its parent — nothing else can grant it.'
        );
    }

    public function testVisibleSubtableIsNotAutoGranted(): void
    {
        $this->seedSchema([
            'zadania'    => ['subtables' => [['table' => 'checklisty'], ['table' => 'komentarze']]],
            'checklisty' => ['hidden' => true],
            'komentarze' => [],
        ]);
        $this->seedTables('119', ['zadania']);
        $_SESSION['user_id'] = 119;

        // komentarze is a normal table: it has a menu entry and a grid of its own, so
        // an admin who did not tick it meant not to grant it.
        $this->assertFalse(
            user_can_access_table('komentarze'),
            'A visible subtable must stay an explicit choice.'
        );
    }

    public function testHiddenClosureIsTransitive(): void
    {
        // projekty → zadania (hidden) → checklisty (hidden): granting the root has to
        // reach the whole hidden chain, not just its first link.
        $this->seedSchema([
            'projekty'   => ['subtables' => [['table' => 'zadania']]],
            'zadania'    => ['hidden' => true, 'subtables' => [['table' => 'checklisty']]],
            'checklisty' => ['hidden' => true],
        ]);
        $this->seedTables('120', ['projekty']);
        $_SESSION['user_id'] = 120;

        $this->assertTrue(user_can_access_table('zadania'));
        $this->assertTrue(user_can_access_table('checklisty'));
    }

    public function testSubtableCycleTerminates(): void
    {
        // A misconfigured schema must not hang the request. Reaching the assertion at
        // all is the point of this test.
        $this->seedSchema([
            'a' => ['subtables' => [['table' => 'b']]],
            'b' => ['hidden' => true, 'subtables' => [['table' => 'a'], ['table' => 'c']]],
            'c' => ['hidden' => true, 'subtables' => [['table' => 'b']]],
        ]);
        $this->seedTables('121', ['a']);
        $_SESSION['user_id'] = 121;

        $this->assertSame(['a', 'b', 'c'], user_allowed_tables());
    }

    public function testClosureIgnoresMalformedSubtableEntries(): void
    {
        $this->seedSchema([
            'orders' => ['subtables' => [['no_table_key' => 1], 'not-an-array', ['table' => '']]],
        ]);
        $this->seedTables('122', ['orders']);
        $_SESSION['user_id'] = 122;

        $this->assertSame(['orders'], user_allowed_tables());
    }

    public function testUnrestrictedUserIsUnaffectedByTheClosure(): void
    {
        $this->seedSchema([
            'zadania'    => ['subtables' => [['table' => 'checklisty']]],
            'checklisty' => ['hidden' => true],
        ]);
        $this->seed(['123' => ['views' => ['v_sales']]]);
        $_SESSION['user_id'] = 123;

        // No table restriction at all still means null, not a closed-over list.
        $this->assertNull(user_allowed_tables());
    }
}
