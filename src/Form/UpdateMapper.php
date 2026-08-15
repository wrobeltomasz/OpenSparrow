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

    public function fromPost(TableConfig $cfg, array $postData): RecordData
    {
        $bindings = [];
        foreach ($cfg->writableColumns() as $col) {
            $hasFk      = $cfg->hasForeignKey($col->name);
            $bound      = $this->registry->for($col, $hasFk)->bind($col->name, $postData);
            $this->assertMatchesRegexp($col, $bound->value);
            $bindings[] = ['col' => $col->name, 'bound' => $bound];
        }
        return new RecordData($bindings);
    }

    private function assertMatchesRegexp(ColumnConfig $col, mixed $value): void
    {
        if ($col->validationRegexp === null || !is_string($value) || $value === '') {
            return;
        }

        $result = @preg_match('~' . str_replace('~', '\~', $col->validationRegexp) . '~u', $value);
        if ($result === false) {
            error_log('[UpdateMapper] invalid validation_regexp in schema.json: ' . $col->validationRegexp);
            return;
        }
        if ($result !== 1) {
            throw new ValidationException($col->validationMessage ?? 'Invalid format: ' . $col->name);
        }
    }
}
