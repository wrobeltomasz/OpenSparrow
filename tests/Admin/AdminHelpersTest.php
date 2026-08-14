<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure helpers in includes/admin/helpers.php — the shared
 * layer the admin modules were refactored onto.
 *
 * Only the side-effect-free helpers are covered here. The rest either exit
 * (admin_ok/admin_err/admin_try) or need a database connection, and are
 * exercised end-to-end through the admin endpoint instead.
 */
final class AdminHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/admin/helpers.php';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
    }

    public function testAdminUserIdReadsTheSession(): void
    {
        $_SESSION['user_id'] = '42';
        $this->assertSame(42, admin_user_id());
    }

    public function testAdminUserIdIsNullWithoutASession(): void
    {
        unset($_SESSION['user_id']);
        $this->assertNull(admin_user_id());
    }

    public function testExpectedVersionAcceptsNumericStrings(): void
    {
        $this->assertSame(7, admin_expected_version(['version' => '7']));
        $this->assertSame(7, admin_expected_version(['version' => 7]));
    }

    /**
     * A missing or non-numeric version means "no optimistic lock" — it must not
     * degrade into version 0, which would collide with a real first revision.
     */
    public function testExpectedVersionIsNullWhenAbsentOrNonNumeric(): void
    {
        $this->assertNull(admin_expected_version([]));
        $this->assertNull(admin_expected_version(['version' => null]));
        $this->assertNull(admin_expected_version(['version' => 'latest']));
    }

    /**
     * A request that names no window gets the caller's default, and only a missing
     * key or an explicit null counts as "none named". '' must NOT land here: the
     * clickstats purge clears its whole table when no window is given, so an empty
     * field arriving as "no window" would answer a retention request with a wipe.
     */
    public function testPurgeDaysIsNullOnlyWhenNoWindowIsNamed(): void
    {
        $this->assertNull(admin_purge_days([]));
        $this->assertNull(admin_purge_days(['days' => null]));
    }

    public function testPurgeDaysAcceptsWholeDayCounts(): void
    {
        $this->assertSame(30, admin_purge_days(['days' => 30]));
        $this->assertSame(30, admin_purge_days(['days' => '30']));
        $this->assertSame(1, admin_purge_days(['days' => 1]));
        $this->assertSame(ADMIN_PURGE_MAX_DAYS, admin_purge_days(['days' => ADMIN_PURGE_MAX_DAYS]));
    }

    /**
     * Every one of these used to become "older than 1 day" via max(1, (int) $raw) —
     * a near-total delete produced by input that was never a day count. They must
     * now be refused, not coerced.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unusableDayCounts(): array
    {
        // Data providers run before setUpBeforeClass(), so the constant this case
        // list is built from is not loaded yet unless we ask for it here.
        require_once __DIR__ . '/../../includes/admin/helpers.php';

        return [
            'zero'            => [0],
            'negative'        => [-5],
            'empty string'    => [''],
            'not a number'    => ['abc'],
            'number and text' => ['30 dni'],
            'true'            => [true],
            'array'           => [[30]],
            'fractional'      => [3.5],
            'over the cap'    => [ADMIN_PURGE_MAX_DAYS + 1],
            'int overflow'    => ['9999999999999999999'],
        ];
    }

    #[DataProvider('unusableDayCounts')]
    public function testPurgeDaysRejectsAnUnusableWindow(mixed $raw): void
    {
        $this->expectException(\AdminApiMessage::class);
        admin_purge_days(['days' => $raw]);
    }

    public function testHelpersAreDefinedOnce(): void
    {
        foreach (
            [
                'admin_conn', 'admin_user_id', 'admin_input', 'admin_ok', 'admin_err',
                'admin_try', 'admin_fetch_all', 'admin_config_save_versioned',
                'admin_expected_version', 'admin_require_log_table', 'admin_purge_log',
                'admin_purge_days', 'admin_read_settings', 'admin_write_settings',
                'admin_save_settings', 'admin_run_cron_script',
            ] as $fn
        ) {
            $this->assertTrue(function_exists($fn), "Helper {$fn}() is missing.");
        }
    }

    /**
     * require_not_demo() must live in the shared helper layer, not be redefined
     * per endpoint — public/admin/api_csv_import.php uses os_api_bootstrap()
     * and would otherwise have no access to it.
     */
    public function testRequireNotDemoIsSharedAndDefaultsTo403(): void
    {
        $this->assertTrue(function_exists('require_not_demo'));

        $fn = new \ReflectionFunction('require_not_demo');
        $this->assertSame(
            403,
            $fn->getParameters()[1]->getDefaultValue(),
            'A demo-mode rejection should carry HTTP 403, not a bare 200 body.'
        );
        $this->assertStringContainsString(
            'includes' . DIRECTORY_SEPARATOR . 'api_helpers.php',
            (string) $fn->getFileName(),
            'require_not_demo() must be defined in includes/api_helpers.php.'
        );
    }
}
