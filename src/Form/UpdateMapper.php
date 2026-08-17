<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Form;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;

final readonly class UpdateMapper
{
    public function __construct(private FieldTypeRegistry $registry)
    {
    }

    public function fromPost(TableConfig $config, array $postData): RecordData
    {
        $bindings = [];
        foreach ($config->writableColumns() as $column) {
            $hasFk      = $config->hasForeignKey($column->name);
            $bound      = $this->registry->for($column, $hasFk)->bind($column->name, $postData);
            $this->assertMatchesRegexp($column, $bound->value);
            $bindings[] = ['col' => $column->name, 'bound' => $bound];
        }
        return new RecordData($bindings);
    }

    private function assertMatchesRegexp(ColumnConfig $column, mixed $value): void
    {
        if ($column->validationRegexp === null || !is_string($value) || $value === '') {
            return;
        }

        $result = @preg_match('~' . str_replace('~', '\~', $column->validationRegexp) . '~u', $value);
        if ($result === false) {
            error_log('[UpdateMapper] invalid validation_regexp in schema.json: ' . $column->validationRegexp);
            return;
        }
        if ($result !== 1) {
            throw new ValidationException($column->validationMessage ?? 'Invalid format: ' . $column->name);
        }
    }
}
