<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class SharedLinkEndpointGuardTest extends TestCase
{
    private const SHARE_ENDPOINT = 'public/api/share.php';

    private const SHARE_PAGE = 'public/share.php';

    private function code(string $relativePath): string
    {
        $path = __DIR__ . '/../../' . $relativePath;
        $this->assertFileExists($path);

        $output = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }

        return (string) preg_replace('/\s+/', ' ', $output);
    }

    private function assertCodeHas(string $needle, string $source, string $reason): void
    {
        $this->assertTrue(str_contains($source, $needle), $reason . ' Expected to find: ' . $needle);
    }

    public function testEndpointResolvesTheTokenBeforeTheTableIsAssigned(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $resolve = strpos($source, '->entryByToken(');
        $assign = strpos($source, "\$_GET['table']");

        $this->assertIsInt($resolve, 'The share endpoint no longer resolves the token through the repository.');
        $this->assertIsInt($assign, 'The share endpoint no longer assigns the server-resolved table.');

        $this->assertLessThan(
            $assign,
            $resolve,
            'The table assigned into $_GET must come from the token lookup, never from the request — '
            . 'the lookup has to run first.'
        );
    }

    public function testEndpointRechecksShareabilityBeforeDelegating(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $recheck = strpos($source, 'SharedLinkValidator::isShareable(');
        $delegate = strpos($source, "define('OS_TABLE_ACCESS_DELEGATED'");

        $this->assertIsInt($recheck, 'The share endpoint must re-check that the resolved table is still shareable.');
        $this->assertIsInt($delegate, 'The share endpoint must mark the delegation before calling frontapi_list.');

        $this->assertLessThan(
            $delegate,
            $recheck,
            'A hand-edited config or a stale schema could point a link at a hidden, owner-restricted or '
            . 'system table; the re-check must run before the gate is waived, exactly like '
            . 'ExternalApiController does.'
        );
    }

    public function testEndpointRefusesNonGetRequests(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $this->assertCodeHas(
            "'GET') !== 'GET'",
            $source,
            'The share endpoint is read-only and must answer 405 to anything but GET.'
        );
    }

    public function testEndpointRateLimitsBeforeResolvingTheToken(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $ipLimit = strpos($source, 'share_ip_');
        $resolve = strpos($source, '->entryByToken(');
        $tokenLimit = strpos($source, 'share_t_');

        $this->assertIsInt($ipLimit, 'The share endpoint must throttle by IP.');
        $this->assertIsInt($tokenLimit, 'The share endpoint must throttle per token.');
        $this->assertLessThan($resolve, $ipLimit, 'The IP limit must run before the token is resolved.');
        $this->assertGreaterThan($ipLimit, $tokenLimit, 'The token limit runs after the IP limit.');
    }

    public function testEndpointNeverBootsTheSessionApi(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $this->assertFalse(
            str_contains($source, 'os_api_bootstrap('),
            'The share endpoint is sessionless by design — os_api_bootstrap() requires a session '
            . 'and would 401 every guest.'
        );
    }

    public function testPageAndEndpointShareTheTokenResolutionPath(): void
    {
        $page = $this->code(self::SHARE_PAGE);
        $endpoint = $this->code(self::SHARE_ENDPOINT);

        $this->assertCodeHas(
            'new SharedLinkRepository()',
            $page,
            'public/share.php must resolve the token through the same repository as the data endpoint.'
        );
        $this->assertCodeHas(
            'SharedLinkValidator::isShareable(',
            $page,
            'public/share.php must apply the same shareability re-checks as the data endpoint.'
        );
        $this->assertCodeHas(
            'new SharedLinkRepository()',
            $endpoint,
            'public/api/share.php must resolve the token through the same repository as the page.'
        );
    }

    public function testPageIsGuestAndNoIndex(): void
    {
        $page = $this->code(self::SHARE_PAGE);

        $this->assertCodeHas(
            "'guest' => true",
            $page,
            'public/share.php must boot as a guest page — a link holder never logs in.'
        );
        $this->assertCodeHas(
            'X-Robots-Tag: noindex',
            $page,
            'A shared link is a secret URL; the page must send X-Robots-Tag: noindex.'
        );
        $this->assertCodeHas(
            'noindex, nofollow',
            $page,
            'The page must also carry the robots meta tag for defense in depth.'
        );
    }

    public function testDisabledLinkIsRejected(): void
    {
        $source = $this->code(self::SHARE_ENDPOINT);

        $this->assertCodeHas(
            "empty(\$matched['entry']['enabled'])",
            $source,
            'A disabled link must 404 — revoking a link takes effect on the next request.'
        );
    }
}