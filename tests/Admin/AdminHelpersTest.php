<?php

declare(strict_types=1);

namespace Tests\Admin;

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

    public function testHelpersAreDefinedOnce(): void
    {
        foreach (
            [
                'admin_conn', 'admin_user_id', 'admin_input', 'admin_ok', 'admin_err',
                'admin_try', 'admin_fetch_all', 'admin_config_save_versioned',
                'admin_expected_version', 'admin_require_log_table', 'admin_purge_log',
                'admin_read_settings', 'admin_write_settings', 'admin_save_settings',
                'admin_run_cron_script',
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
