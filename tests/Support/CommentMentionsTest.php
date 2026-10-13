<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class CommentMentionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/comment_mentions.php';
    }

    public function testExtractsSingleMention(): void
    {
        $this->assertSame(
            ['demo.marek'],
            comment_extract_mentions('Hi @demo.marek please review this.')
        );
    }

    public function testExtractsMultipleAndDeduplicatesInOrder(): void
    {
        $this->assertSame(
            ['demo.anna', 'demo.marek'],
            comment_extract_mentions('@demo.anna and @demo.marek and @demo.anna again.')
        );
    }

    public function testIgnoresUsernamesShorterThanThreeCharacters(): void
    {
        $this->assertSame([], comment_extract_mentions('@ab @x @yy'));
    }

    public function testIgnoresEmailAddresses(): void
    {
        $this->assertSame([], comment_extract_mentions('Contact foo@bar.com or a.b@example.org'));
    }

    public function testHonoursWordBoundary(): void
    {
        $this->assertSame([], comment_extract_mentions('xx@demo.marek'));
    }

    public function testMatchesAfterPunctuation(): void
    {
        $this->assertSame(
            ['demo.marek'],
            comment_extract_mentions('(@demo.marek) [@demo.marek]')
        );
    }

    public function testStopsAtTrailingPunctuation(): void
    {
        $this->assertSame(
            ['demo.marek', 'demo.anna'],
            comment_extract_mentions('@demo.marek, and @demo.anna!')
        );
    }

    public function testReturnsEmptyForNoMentions(): void
    {
        $this->assertSame([], comment_extract_mentions('Plain text with **markdown** and http://example.com'));
    }
}
