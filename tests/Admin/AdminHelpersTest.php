<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    public function testExpectedVersionIsNullWhenAbsentOrNonNumeric(): void
    {
        $this->assertNull(admin_expected_version([]));
        $this->assertNull(admin_expected_version(['version' => null]));
        $this->assertNull(admin_expected_version(['version' => 'latest']));
    }

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

    public static function unusableDayCounts(): array
    {
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
    public function testPurgeDaysRejectsAnUnusableWindow(mixed $rawDays): void
    {
        $this->expectException(\AdminApiMessage::class);
        admin_purge_days(['days' => $rawDays]);
    }

    public function testPurgeScopeReadsAWindowOrAnExplicitClearAll(): void
    {
        $this->assertSame(30, admin_purge_scope(['days' => 30]));
        $this->assertSame(30, admin_purge_scope(['days' => '30']));
        $this->assertSame(ADMIN_PURGE_ALL, admin_purge_scope(['all' => true]));
    }

    public static function refusedPurgeScopes(): array
    {
        return [
            'names nothing'      => [[]],
            'all is false'       => [['all' => false]],
            'all is null'        => [['all' => null]],
            'all is 1 not true'  => [['all' => 1]],
            'all is the string'  => [['all' => 'true']],
            'window and all'     => [['days' => 30, 'all' => true]],
            'window and all off' => [['days' => 30, 'all' => false]],
            'unusable window'    => [['days' => '30 dni']],
        ];
    }

    #[DataProvider('refusedPurgeScopes')]
    public function testPurgeScopeRefusesAnythingImplicitOrAmbiguous(array $input): void
    {
        $this->expectException(\AdminApiMessage::class);
        admin_purge_scope($input);
    }

    public static function unusableResolvedWindows(): array
    {
        require_once __DIR__ . '/../../includes/admin/helpers.php';

        return [
            'zero deletes everything' => [0],
            'negative'                => [-1],
            'far negative'            => [-3650],
            'over the cap'            => [ADMIN_PURGE_MAX_DAYS + 1],
        ];
    }

    #[DataProvider('unusableResolvedWindows')]
    public function testPurgeOlderThanRefusesAnUnusableWindow(int $days): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->expectExceptionMessage('Retention window must be a whole number of days');
        admin_purge_older_than('"app"."spw_no_such_table_for_tests"', $days, 'test', 'created_at');
    }

    public function testHelpersAreDefinedOnce(): void
    {
        foreach (
            [
                'admin_conn', 'admin_user_id', 'admin_input', 'admin_ok', 'admin_err',
                'admin_try', 'admin_fetch_all', 'admin_config_save_versioned',
                'admin_expected_version', 'admin_require_log_table', 'admin_purge_log',
                'admin_purge_older_than', 'admin_purge_days', 'admin_purge_scope',
                'admin_read_settings', 'admin_write_settings', 'admin_save_settings',
                'admin_run_cron_script',
            ] as $callback
        ) {
            $this->assertTrue(function_exists($callback), "Helper {$callback}() is missing.");
        }
    }

    public function testRequireNotDemoIsSharedAndDefaultsTo403(): void
    {
        $this->assertTrue(function_exists('require_not_demo'));

        $callback = new \ReflectionFunction('require_not_demo');
        $this->assertSame(
            403,
            $callback->getParameters()[1]->getDefaultValue(),
            'A demo-mode rejection should carry HTTP 403, not a bare 200 body.'
        );
        $this->assertStringContainsString(
            'includes' . DIRECTORY_SEPARATOR . 'api_helpers.php',
            (string) $callback->getFileName(),
            'require_not_demo() must be defined in includes/api_helpers.php.'
        );
    }
}
