<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Http;

use App\Http\PhpSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpSessionTest extends TestCase
{
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    public static function userIdCases(): array
    {
        return [
            'absent'        => [null, 0],
            'integer'       => [7, 7],
            'numeric string' => ['7', 7],
            'zero'          => [0, 0],
            'empty string'  => ['', 0],
        ];
    }

    #[DataProvider('userIdCases')]
    public function testUserIdMatchesTheCastItReplaced(mixed $stored, int $expected): void
    {
        $_SESSION = [];
        if ($stored !== null) {
            $_SESSION['user_id'] = $stored;
        }

        $session = new PhpSession();

        self::assertSame($expected, $session->userId());
        self::assertSame((int) ($_SESSION['user_id'] ?? 0), $session->userId());
    }

    #[DataProvider('userIdCases')]
    public function testNullableUserIdKeepsAbsentDistinctFromZero(mixed $stored, int $expected): void
    {
        $_SESSION = [];
        if ($stored !== null) {
            $_SESSION['user_id'] = $stored;
        }

        $session = new PhpSession();

        $legacy  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $current = $session->has('user_id') ? $session->userId() : null;

        self::assertSame($legacy, $current);
        self::assertSame($stored === null ? null : $expected, $current);
    }

    public function testAbsentUserIdIsNullNotZeroForConfigAuthorship(): void
    {
        $_SESSION = [];
        $session  = new PhpSession();

        self::assertNull($session->has('user_id') ? $session->userId() : null);
        self::assertSame(0, $session->userId());
    }

    public function testRoleDefaultsToViewerButGetKeepsItsOwnDefault(): void
    {
        $_SESSION = [];
        $session  = new PhpSession();

        self::assertSame('viewer', $session->role());
        self::assertSame('editor', $session->get('role', 'editor'));
        self::assertSame('', $session->get('role', ''));

        $_SESSION['role'] = 'admin';
        self::assertSame('admin', $session->role());
        self::assertSame('admin', $session->get('role', 'editor'));
    }
}
