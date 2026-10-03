<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use App\Service\SharedLinkRepository;
use App\Service\SharedLinkValidator;
use App\Service\SharedTokenManager;
use PHPUnit\Framework\TestCase;

final class SharedLinkTest extends TestCase
{
    private SharedLinkValidator $validator;

    private SharedTokenManager $tokenManager;

    private SharedLinkRepository $repository;

    private array $schema;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/config.php';
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/crypto.php';
        require_once __DIR__ . '/../../includes/admin_api_errors.php';
    }

    protected function setUp(): void
    {
        $this->validator = new SharedLinkValidator();
        $this->tokenManager = new SharedTokenManager();
        $this->repository = new SharedLinkRepository();
        $this->schema = [
            'tables' => [
                'customers' => ['display_name' => 'Customers', 'columns' => []],
                'orders' => ['display_name' => 'Orders', 'columns' => []],
                'secret_list' => ['display_name' => 'Secret', 'hidden' => true, 'columns' => []],
                'private_rows' => ['display_name' => 'Private', 'owner_restricted' => true, 'columns' => []],
                'spw_config' => ['display_name' => 'Config', 'columns' => []],
            ],
        ];
    }

    public function testDefaultsReturnAnEmptyTableMap(): void
    {
        $this->assertSame(['tables' => []], $this->repository->defaults());
    }

    public function testGeneratedTokenCarriesItsHash(): void
    {
        $generated = $this->tokenManager->generate();

        $this->assertSame(64, strlen($generated['token']));
        $this->assertSame(secret_hash($generated['token']), $generated['token_hash']);
    }

    public function testMatchesOnTheStoredHash(): void
    {
        $generated = $this->tokenManager->generate();

        $this->assertTrue($this->tokenManager->matches(
            ['token_hash' => $generated['token_hash']],
            $generated['token']
        ));
    }

    public function testMatchesRejectsAnEmptyStoredHash(): void
    {
        $this->assertFalse($this->tokenManager->matches(['token_hash' => ''], 'anything'));
    }

    public function testMatchesRejectsAWrongToken(): void
    {
        $generated = $this->tokenManager->generate();

        $this->assertFalse($this->tokenManager->matches(
            ['token_hash' => $generated['token_hash']],
            'not-the-token'
        ));
    }

    public function testIsShareableAcceptsARegularTable(): void
    {
        $this->assertTrue(SharedLinkValidator::isShareable($this->schema, 'customers'));
    }

    public function testIsShareableRejectsAHiddenTable(): void
    {
        $this->assertFalse(SharedLinkValidator::isShareable($this->schema, 'secret_list'));
    }

    public function testIsShareableRejectsAnOwnerRestrictedTable(): void
    {
        $this->assertFalse(SharedLinkValidator::isShareable($this->schema, 'private_rows'));
    }

    public function testIsShareableRejectsASystemTable(): void
    {
        $this->assertFalse(SharedLinkValidator::isShareable($this->schema, 'spw_config'));
    }

    public function testIsShareableRejectsAnUnknownTable(): void
    {
        $this->assertFalse(SharedLinkValidator::isShareable($this->schema, 'ghost'));
    }

    public function testShareableTablesListOnlyEligibleNames(): void
    {
        $this->assertSame(['customers', 'orders'], $this->validator->shareableTables($this->schema));
    }

    public function testValidateTableNameRejectsAHiddenTable(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validateTableName($this->schema, 'secret_list');
    }

    public function testValidateTableNameRejectsASystemTable(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validateTableName($this->schema, 'spw_config');
    }

    public function testValidateTableNameRejectsAnOwnerRestrictedTable(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validateTableName($this->schema, 'private_rows');
    }

    public function testValidateTableNameRejectsAnUnknownTable(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validateTableName($this->schema, 'ghost');
    }

    public function testValidateTableNameAcceptsARegularTable(): void
    {
        $this->validator->validateTableName($this->schema, 'customers');
        $this->expectNotToPerformAssertions();
    }

    public function testBoolCoercionAcceptsTruthyValues(): void
    {
        $this->assertTrue(SharedLinkValidator::coercBool('true'));
        $this->assertTrue(SharedLinkValidator::coercBool('1'));
        $this->assertTrue(SharedLinkValidator::coercBool(1));
        $this->assertTrue(SharedLinkValidator::coercBool(true));
    }

    public function testBoolCoercionRejectsFalsyValues(): void
    {
        $this->assertFalse(SharedLinkValidator::coercBool('false'));
        $this->assertFalse(SharedLinkValidator::coercBool('0'));
        $this->assertFalse(SharedLinkValidator::coercBool(0));
        $this->assertFalse(SharedLinkValidator::coercBool(null));
    }

    public function testPruneEntriesDropsTablesRemovedFromTheSchema(): void
    {
        $existingTables = [
            'customers' => ['enabled' => true, 'token_hash' => 'abc'],
            'orders' => ['enabled' => false, 'token_hash' => 'def'],
            'dropped_table' => ['enabled' => false, 'token_hash' => 'dead-token-hash'],
        ];

        $pruned = SharedLinkRepository::pruneEntries($existingTables, $this->schema);

        $this->assertArrayNotHasKey('dropped_table', $pruned);
        $this->assertArrayHasKey('customers', $pruned);
        $this->assertArrayHasKey('orders', $pruned);
        $this->assertSame($existingTables['customers'], $pruned['customers']);
        $this->assertSame($existingTables['orders'], $pruned['orders']);
    }

    public function testPruneEntriesKeepsEverythingWhenTheSchemaHasNoTables(): void
    {
        $existingTables = ['customers' => ['enabled' => true, 'token_hash' => 'abc']];

        $this->assertSame(
            [],
            SharedLinkRepository::pruneEntries($existingTables, ['tables' => []])
        );
        $this->assertSame(
            [],
            SharedLinkRepository::pruneEntries($existingTables, [])
        );
    }
}