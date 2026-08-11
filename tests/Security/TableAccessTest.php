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
        user_allowed_items('boards');
    }

    public function testFilterByUserAccessNarrowsViews(): void
    {
        $this->seed(['117' => ['views' => ['v_sales']]]);
        $_SESSION['user_id'] = 117;

        $views = ['v_payroll' => 1, 'v_sales' => 2];
        $this->assertSame(['v_sales'], array_keys(filter_by_user_access('views', $views)));
    }
}
