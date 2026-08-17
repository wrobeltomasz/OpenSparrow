<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AppContext;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

final class AppContextTest extends TestCase
{
    private const CONNECTION_BOUND = [
        'conn', 'database', 'services', 'schemas', 'fkLoader', 'records', 'files', 'audit',
    ];

    private function built(AppContext $context): array
    {
        $built = [];
        foreach ((new ReflectionObject($context))->getProperties() as $property) {
            if ($property->getValue($context) !== null) {
                $built[] = $property->getName();
            }
        }
        sort($built);
        return $built;
    }

    public function testTheConstructorBuildsNothing(): void
    {
        $this->assertSame(
            [],
            $this->built(new AppContext()),
            'AppContext built a dependency before anything asked for one. The API endpoints '
            . 'that boot with connect => false rely on nothing being constructed up front.'
        );
    }

    public function testReadingTheSessionAndCsrfTokenOpensNoConnection(): void
    {
        $context = new AppContext();
        $context->session();
        $context->csrf();

        $connectionBound = array_intersect(self::CONNECTION_BOUND, $this->built($context));

        $this->assertSame(
            [],
            array_values($connectionBound),
            'Reading the session or the CSRF manager reached the database. An endpoint that '
            . 'answers 401/403 before connecting would start connecting first.'
        );
    }

    public function testAccessorsAreMemoised(): void
    {
        $context = new AppContext();

        $this->assertSame($context->session(), $context->session());
        $this->assertSame($context->csrf(), $context->csrf());
        $this->assertSame($context->fieldRegistry(), $context->fieldRegistry());
        $this->assertSame($context->mapper(), $context->mapper());
    }

    public function testTheFieldRegistryAndMapperNeedNoConnection(): void
    {
        $context = new AppContext();
        $context->mapper();

        $connectionBound = array_intersect(self::CONNECTION_BOUND, $this->built($context));

        $this->assertSame([], array_values($connectionBound));
    }

    public function testOnlyTheConnectionAccessorConnects(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Service/AppContext.php');

        $this->assertSame(
            1,
            substr_count($source, 'db_connect()'),
            'db_connect() is called more than once in AppContext. Every dependency must reach '
            . 'the connection through connection(), or laziness stops being a guarantee.'
        );
        $this->assertStringContainsString(
            'return $this->conn ??= db_connect();',
            $source
        );
    }
}
