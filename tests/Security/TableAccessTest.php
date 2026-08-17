<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

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

        $this->seedSchema([]);
        foreach (USER_ACCESS_SCOPES as $definition) {
            $this->seedConfig($definition['config'], [$definition['path'] => []]);
        }
    }

    private function seed(array $users): void
    {
        config_cache('user_table_access', ['value' => ['users' => $users], 'version' => 1], true);
    }

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
        $this->seedTables('124', ['orders']);
        $_SESSION['user_id'] = 999;
        $_SESSION['role']    = 'admin';

        $this->assertSame(['orders'], user_allowed_tables(124));
        $this->assertFalse(user_can_access_table('invoices', 124));

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

        $this->assertTrue(user_can_access_table('invoices', 111));
        $this->assertFalse(user_can_access_table('orders', 111));
    }

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
        $this->seed(['115' => ['orders', 'clients']]);
        $_SESSION['user_id'] = 115;

        $this->assertSame(['orders', 'clients'], user_allowed_tables());
        $this->assertFalse(user_can_access_table('invoices'));
        $this->assertTrue(user_can_access_view('any_view'), 'Legacy shape grants no view restriction.');
    }

    public function testUnknownScopeThrows(): void
    {
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

    private function seedConfig(string $key, array $value): void
    {
        config_cache($key, ['value' => $value, 'version' => 1], true);
    }

    public function testScopeItemsNormaliseBothConfigShapes(): void
    {
        $this->seedConfig('views', ['views' => ['v_sales' => ['display_name' => 'Sales']]]);
        $this->seedConfig('board', ['boards' => [['id' => 'brd_1', 'menu_name' => 'Sales Board']]]);

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
        foreach (USER_ACCESS_SCOPES as $scope => $definition) {
            foreach (['config', 'path', 'id', 'label', 'noun', 'plural', 'title', 'empty'] as $field) {
                $this->assertArrayHasKey($field, $definition, "Scope '{$scope}' is missing '{$field}'.");
            }

            $this->assertNotSame(
                $definition['noun'],
                $definition['plural'],
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
        $this->seedConfig('schema', ['tables' => ['orders' => []]]);
        $this->seed(['131' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 131;

        $_GET['table'] = 'no_such_table';
        $this->assertNull(os_request_scope_violation());
    }

    public function testBoundaryGateStillSeesHiddenEntries(): void
    {
        $this->seedConfig('schema', ['tables' => ['orders' => [], 'ukryta' => ['hidden' => true]]]);
        $this->seed(['132' => ['tables' => ['orders']]]);
        $_SESSION['user_id'] = 132;

        $_GET['table'] = 'ukryta';
        $this->assertSame(['tables', 'ukryta'], os_request_scope_violation());
    }

    public function testOmittedScopeKeepsItsStoredValue(): void
    {
        $stored = ['tables' => ['orders'], 'views' => ['v_ok'], 'boards' => ['brd_1']];

        $merged = merge_user_access_selection(['tables' => ['clients']], $stored);

        $this->assertSame(['clients'], $merged['tables'], 'The submitted scope must win.');
        $this->assertSame(['v_ok'], $merged['views'], 'An omitted scope must not be widened.');
        $this->assertSame(['brd_1'], $merged['boards'], 'An omitted scope must not be widened.');
        $this->assertSame([], $merged['prints'], 'Nothing stored and nothing sent stays unrestricted.');
    }

    public function testExplicitEmptyListStillClearsAScope(): void
    {
        $merged = merge_user_access_selection(
            ['views' => []],
            ['tables' => ['orders'], 'views' => ['v_ok']]
        );

        $this->assertSame([], $merged['views'], 'An explicit [] must clear the scope.');
        $this->assertSame(['orders'], $merged['tables']);
    }

    public function testMalformedScopeValueDoesNotWiden(): void
    {
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
        $merged = merge_user_access_selection([], []);

        $this->assertSame(array_keys(USER_ACCESS_SCOPES), array_keys($merged));
    }

    public function testBodyIsNotReadWhenThereCannotBeOne(): void
    {
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
        $source = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../../includes/api_helpers.php')) as $token) {
            $source .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token;
        }

        $this->assertStringNotContainsString(
            "\$_SERVER['CONTENT_TYPE'] ?? '', 'application/json'",
            $source,
            'The gate must not decide whether to read the body from the declared content type.'
        );
        $this->assertStringContainsString(
            "'multipart/form-data'",
            $source,
            'multipart is the one content type the gate must skip — PHP already consumed that stream.'
        );
    }

    public function testBoundaryGateWalksArrayValuedParameters(): void
    {
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

        $this->assertFalse(
            user_can_access_table('komentarze'),
            'A visible subtable must stay an explicit choice.'
        );
    }

    public function testHiddenClosureIsTransitive(): void
    {
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

        $this->assertNull(user_allowed_tables());
    }

    public function testAllDigitTableNameSurvivesTheClosure(): void
    {
        $this->seedSchema([
            '2024' => ['subtables' => [['table' => '2024_lines']]],
            '2024_lines' => ['hidden' => true],
        ]);

        $this->seedTables('138', ['2024']);
        $_SESSION['user_id'] = 138;

        $this->assertSame(['2024', '2024_lines'], user_allowed_tables());
        $this->assertTrue(user_can_access_table('2024'), 'The granted table must stay reachable.');
        $this->assertTrue(user_can_access_table('2024_lines'), 'Its hidden subtable must too.');
        $this->assertFalse(user_can_access_table('2025'), 'The gate must still refuse what was not granted.');
    }
}
