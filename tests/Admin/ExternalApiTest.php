<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Admin;

use App\Service\ApiConfigRepository;
use App\Service\ApiConfigValidator;
use App\Service\ApiKeyManager;
use PHPUnit\Framework\TestCase;

final class ExternalApiTest extends TestCase
{
    private ApiConfigValidator $validator;

    private ApiKeyManager $keyManager;

    private ApiConfigRepository $repository;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/config.php';
        require_once __DIR__ . '/../../includes/db.php';
        require_once __DIR__ . '/../../includes/crypto.php';
        require_once __DIR__ . '/../../includes/admin_api_errors.php';
    }

    protected function setUp(): void
    {
        $this->validator = new ApiConfigValidator();
        $this->keyManager = new ApiKeyManager();
        $this->repository = new ApiConfigRepository();
    }

    public function testDefaultsReturnAnEmptyApiList(): void
    {
        $this->assertSame(['apis' => []], $this->repository->defaults());
    }

    public function testRedactStripsBothKeyFieldsAndFlagsConfigured(): void
    {
        $config = [
            'apis' => [
                ['id' => 'a', 'key_enc' => 'enc', 'key_hash' => 'hash'],
                ['id' => 'b', 'key_enc' => '', 'key_hash' => ''],
            ],
        ];

        $redacted = $this->repository->redact($config);

        $this->assertTrue($redacted['apis'][0]['key_configured']);
        $this->assertFalse($redacted['apis'][1]['key_configured']);
        $this->assertArrayNotHasKey('key_enc', $redacted['apis'][0]);
        $this->assertArrayNotHasKey('key_hash', $redacted['apis'][0]);
        $this->assertArrayNotHasKey('key_enc', $redacted['apis'][1]);
        $this->assertArrayNotHasKey('key_hash', $redacted['apis'][1]);
    }

    public function testRedactSkipsNonArrayEntries(): void
    {
        $config = ['apis' => ['not-an-array']];

        $this->assertSame($config, $this->repository->redact($config));
    }

    public function testValidateRejectsAnEmptyName(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => '  '], ['tables' => []]);
    }

    public function testValidateRejectsAnUnknownTable(): void
    {
        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'missing'], ['tables' => []]);
    }

    public function testValidateRejectsAHiddenTable(): void
    {
        $schema = ['tables' => ['tasks' => ['hidden' => true, 'columns' => []]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'tasks'], $schema);
    }

    public function testValidateRejectsASystemTable(): void
    {
        $schema = ['tables' => ['spw_config' => ['columns' => []]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'spw_config'], $schema);
    }

    public function testValidateRejectsAnOwnerRestrictedTable(): void
    {
        $schema = ['tables' => ['tasks' => ['owner_restricted' => true, 'columns' => []]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'tasks'], $schema);
    }

    public function testValidateRejectsAnUnknownColumn(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['title' => []]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'tasks', 'columns' => ['nope']], $schema);
    }

    public function testValidateRejectsAVirtualColumn(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => [
            'title' => ['type' => 'text'],
            'computed' => ['type' => 'virtual'],
        ]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(['name' => 'x', 'table' => 'tasks', 'columns' => ['computed']], $schema);
    }

    public function testValidateAllowsRealColumnsAlongsideVirtualOnes(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => [
            'title' => ['type' => 'text'],
            'computed' => ['type' => 'virtual'],
        ]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title']],
            $schema
        );

        $this->assertSame(['title'], $validated['columns']);
    }

    public function testValidateRejectsAnUnknownFilterOperator(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['title' => []]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title'], 'filters' => [
                ['column' => 'title', 'operator' => 'like', 'value' => 'a'],
            ]],
            $schema
        );
    }

    public function testValidateDropsEmptyFilterValues(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['title' => []]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title'], 'filters' => [
                ['column' => 'title', 'operator' => 'eq', 'value' => ''],
            ]],
            $schema
        );

        $this->assertSame([], $validated['filters']);
    }

    public function testValidateClampsTheLimit(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['title' => []]]]];

        $this->assertSame(100, $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title'], 'limit' => 99999],
            $schema
        )['limit']);

        $this->assertSame(100, $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title'], 'limit' => 0],
            $schema
        )['limit']);
    }

    public function testValidateRejectsANonNumericValueForANumberColumn(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['amount' => ['type' => 'number']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['amount'], 'filters' => [
                ['column' => 'amount', 'operator' => 'eq', 'value' => 'abc'],
            ]],
            $schema
        );
    }

    public function testValidateAcceptsANumericValueForANumberColumn(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['amount' => ['type' => 'number']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['amount'], 'filters' => [
                ['column' => 'amount', 'operator' => 'eq', 'value' => '42'],
            ]],
            $schema
        );

        $this->assertSame('42', $validated['filters'][0]['value']);
    }

    public function testValidateRejectsANonNumericValueForTheIdColumn(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['title' => ['type' => 'text']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['title'], 'filters' => [
                ['column' => 'id', 'operator' => 'eq', 'value' => 'abc'],
            ]],
            $schema
        );
    }

    public function testValidateNormalizesABooleanValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['done' => ['type' => 'boolean']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['done'], 'filters' => [
                ['column' => 'done', 'operator' => 'eq', 'value' => 'true'],
            ]],
            $schema
        );

        $this->assertSame('TRUE', $validated['filters'][0]['value']);
    }

    public function testValidateRejectsAnInvalidBooleanValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['done' => ['type' => 'boolean']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['done'], 'filters' => [
                ['column' => 'done', 'operator' => 'eq', 'value' => 'maybe'],
            ]],
            $schema
        );
    }

    public function testValidateRejectsAnInvalidDateValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['due' => ['type' => 'date']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['due'], 'filters' => [
                ['column' => 'due', 'operator' => 'eq', 'value' => '2024-13-40'],
            ]],
            $schema
        );
    }

    public function testValidateAcceptsAValidDateValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['due' => ['type' => 'date']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['due'], 'filters' => [
                ['column' => 'due', 'operator' => 'eq', 'value' => '2024-01-31'],
            ]],
            $schema
        );

        $this->assertSame('2024-01-31', $validated['filters'][0]['value']);
    }

    public function testValidateRejectsAnInvalidDatetimeValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['at' => ['type' => 'datetime']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['at'], 'filters' => [
                ['column' => 'at', 'operator' => 'eq', 'value' => 'not-a-date'],
            ]],
            $schema
        );
    }

    public function testValidateAcceptsAValidDatetimeValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['at' => ['type' => 'datetime']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['at'], 'filters' => [
                ['column' => 'at', 'operator' => 'eq', 'value' => '2024-01-31 12:30:00'],
            ]],
            $schema
        );

        $this->assertSame('2024-01-31 12:30:00', $validated['filters'][0]['value']);
    }

    public function testValidateRejectsAnInvalidTimestampValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['at' => ['type' => 'timestamp']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['at'], 'filters' => [
                ['column' => 'at', 'operator' => 'eq', 'value' => 'not-a-date'],
            ]],
            $schema
        );
    }

    public function testValidateAcceptsAValidTimestampValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['at' => ['type' => 'timestamp']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['at'], 'filters' => [
                ['column' => 'at', 'operator' => 'eq', 'value' => '2024-01-31 12:30:00'],
            ]],
            $schema
        );

        $this->assertSame('2024-01-31 12:30:00', $validated['filters'][0]['value']);
    }

    public function testValidateRejectsAnInvalidTimestamptzValue(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['at' => ['type' => 'timestamptz']]]]];

        $this->expectException(\AdminApiMessage::class);
        $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['at'], 'filters' => [
                ['column' => 'at', 'operator' => 'eq', 'value' => 'garbage'],
            ]],
            $schema
        );
    }

    public function testValidateSkipsTypeCoercionForContainsOperator(): void
    {
        $schema = ['tables' => ['tasks' => ['columns' => ['amount' => ['type' => 'number']]]]];

        $validated = $this->validator->validate(
            ['name' => 'x', 'table' => 'tasks', 'columns' => ['amount'], 'filters' => [
                ['column' => 'amount', 'operator' => 'contains', 'value' => '4'],
            ]],
            $schema
        );

        $this->assertSame('4', $validated['filters'][0]['value']);
    }

    public function testResolveKeyEncryptsASubmittedKey(): void
    {
        $resolved = $this->keyManager->resolve(['key' => 'secret'], []);

        $this->assertNotSame('', $resolved['key_enc']);
        $this->assertSame(secret_hash('secret'), $resolved['key_hash']);
        $this->assertNull($resolved['generated']);
    }

    public function testResolveKeyGeneratesWhenNoKeyExists(): void
    {
        $resolved = $this->keyManager->resolve(['key' => ''], []);

        $this->assertNotSame('', $resolved['key_enc']);
        $this->assertNotSame('', $resolved['key_hash']);
        $this->assertNotNull($resolved['generated']);
        $this->assertSame(secret_hash($resolved['generated']), $resolved['key_hash']);
    }

    public function testResolveKeyRegeneratesOnDemand(): void
    {
        $previous = ['key_enc' => 'old', 'key_hash' => 'old-hash'];
        $resolved = $this->keyManager->resolve(['key' => '', 'key_regenerate' => true], $previous);

        $this->assertNotSame('old', $resolved['key_enc']);
        $this->assertNotSame('old-hash', $resolved['key_hash']);
        $this->assertNotNull($resolved['generated']);
    }

    public function testResolveKeyKeepsAnExistingKey(): void
    {
        $previous = ['key_enc' => 'enc', 'key_hash' => 'hash'];
        $resolved = $this->keyManager->resolve(['key' => ''], $previous);

        $this->assertSame('enc', $resolved['key_enc']);
        $this->assertSame('hash', $resolved['key_hash']);
        $this->assertNull($resolved['generated']);
    }

    public function testResolveKeyBackfillsAMissingHash(): void
    {
        $plainKey = 'legacy-plain-key';
        $previous = ['key_enc' => secret_encrypt($plainKey), 'key_hash' => ''];

        $resolved = $this->keyManager->resolve(['key' => ''], $previous);

        $this->assertSame($previous['key_enc'], $resolved['key_enc']);
        $this->assertSame(secret_hash($plainKey), $resolved['key_hash']);
        $this->assertNull($resolved['generated']);
    }

    public function testResolveKeyDoesNotRegenerateOnStringFalse(): void
    {
        $previous = ['key_enc' => 'enc', 'key_hash' => 'hash'];
        $resolved = $this->keyManager->resolve(['key' => '', 'key_regenerate' => 'false'], $previous);

        $this->assertSame('enc', $resolved['key_enc']);
        $this->assertSame('hash', $resolved['key_hash']);
        $this->assertNull($resolved['generated']);
    }

    public function testResolveKeyRegeneratesOnStringTrue(): void
    {
        $previous = ['key_enc' => 'enc', 'key_hash' => 'hash'];
        $resolved = $this->keyManager->resolve(['key' => '', 'key_regenerate' => 'true'], $previous);

        $this->assertNotSame('enc', $resolved['key_enc']);
        $this->assertNotSame('hash', $resolved['key_hash']);
        $this->assertNotNull($resolved['generated']);
    }

    public function testBoolCoercionRejectsStringFalse(): void
    {
        $this->assertFalse(ApiConfigValidator::coerceBool('false'));
        $this->assertFalse(ApiConfigValidator::coerceBool('0'));
        $this->assertFalse(ApiConfigValidator::coerceBool(0));
        $this->assertFalse(ApiConfigValidator::coerceBool('no'));
    }

    public function testBoolCoercionAcceptsTruthyValues(): void
    {
        $this->assertTrue(ApiConfigValidator::coerceBool('true'));
        $this->assertTrue(ApiConfigValidator::coerceBool('1'));
        $this->assertTrue(ApiConfigValidator::coerceBool(1));
        $this->assertTrue(ApiConfigValidator::coerceBool(true));
    }

    public function testBoolCoercionFallsBackToDefault(): void
    {
        $this->assertTrue(ApiConfigValidator::coerceBool(null, true));
        $this->assertFalse(ApiConfigValidator::coerceBool(null));
        $this->assertFalse(ApiConfigValidator::coerceBool('garbage'));
    }
}
