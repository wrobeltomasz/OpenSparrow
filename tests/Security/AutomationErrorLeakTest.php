<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class AutomationErrorLeakTest extends TestCase
{
    private const AUTOMATIONS_FILE = __DIR__ . '/../../includes/automations.php';

    private static function code(): string
    {
        $output = '';
        foreach (token_get_all((string) file_get_contents(self::AUTOMATIONS_FILE)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $output .= $token[1];
                continue;
            }
            $output .= $token;
        }
        return $output;
    }

    public function testFileExists(): void
    {
        $this->assertFileExists(self::AUTOMATIONS_FILE);
    }

    public function testActionErrorReturnsNeverEmbedPgLastError(): void
    {
        $source = self::code();

        $returned = [];
        if (preg_match_all('/return[^;]*pg_last_error[^;]*;/', $source, $matches) > 0) {
            $returned = $matches[0];
        }
        $this->assertSame(
            [],
            $returned,
            'includes/automations.php must never return pg_last_error() text from an action helper. '
            . 'It flows into spw_automation_runs.error_msg and the admin automations_runs endpoint '
            . 'serves that column to the client. Log it via error_log() and return a generic message. '
            . 'Offending statements: ' . implode(' | ', $returned)
        );
    }

    public function testActionErrorNeverCollectsPgLastError(): void
    {
        $source = self::code();

        $collected = [];
        if (preg_match_all('/\$errors\[\][^;]*pg_last_error[^;]*;/', $source, $matches) > 0) {
            $collected = $matches[0];
        }
        $this->assertSame(
            [],
            $collected,
            'includes/automations.php must never append pg_last_error() text to an error list '
            . 'that reaches the caller. Log it via error_log() and append a generic message. '
            . 'Offending statements: ' . implode(' | ', $collected)
        );
    }
}