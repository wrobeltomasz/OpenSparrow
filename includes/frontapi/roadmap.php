<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;
use App\Exception\ResponseException;

function frontapi_roadmap(FrontApiContext $context): never
{
    $conn   = $context->conn;
    $schema = $context->schema;

    $roadmapsConfig = config_get('roadmap') ?? [];
    $roadmapId  = substr($_GET['roadmap'] ?? '', 0, 64);

    $roadmaps  = filter_by_user_access('roadmaps', $roadmapsConfig['roadmaps'] ?? []);
    $roadmapConfig = null;
    foreach ($roadmaps as $roadmap) {
        if (($roadmap['id'] ?? '') === $roadmapId) {
            $roadmapConfig = $roadmap;
            break;
        }
    }
    if ($roadmapConfig === null) {
        $roadmapConfig = $roadmaps[0] ?? [];
    }

    $meta = [
        'menu_name'       => $roadmapConfig['menu_name'] ?? 'Roadmap',
        'menu_icon'       => $roadmapConfig['menu_icon'] ?? '',
        'hidden'          => !empty($roadmapConfig['hidden']),
        'configured'      => false,
        'table'           => $roadmapConfig['table'] ?? '',
        'start_column'    => $roadmapConfig['start_column'] ?? '',
        'end_column'      => $roadmapConfig['end_column'] ?? '',
        'categories'      => [],
        'rows'            => [],
        'can_edit'        => false,
    ];

    $table       = $roadmapConfig['table'] ?? '';
    $startColumn = $roadmapConfig['start_column'] ?? '';
    $endColumn   = $roadmapConfig['end_column'] ?? '';
    if ($table === '' || $startColumn === '' || $endColumn === '') {
        throw ResponseException::encoded($meta);
    }

    if (!user_can_access_table($table)) {
        $meta['table']        = '';
        $meta['start_column'] = '';
        $meta['end_column']   = '';
        throw ResponseException::encoded($meta);
    }

    try {
        $tableConfig = safe_table($schema, $table);
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        throw ResponseException::encoded($meta);
    }

    if (!isset($tableConfig['columns'][$startColumn]) || !isset($tableConfig['columns'][$endColumn])) {
        throw ResponseException::encoded($meta);
    }

    $schemaName = $tableConfig['schema'] ?? 'public';
    $idColumn   = id_column();
    $titleColumn = $roadmapConfig['title_column'] ?? '';
    if ($titleColumn === '' || !isset($tableConfig['columns'][$titleColumn])) {
        $titleColumn = $idColumn;
    }
    $defaultColor = $roadmapConfig['color'] ?? '#003366';

    $categoryColumn = $roadmapConfig['category_column'] ?? '';
    $hasCategory    = $categoryColumn !== '' && isset($tableConfig['columns'][$categoryColumn]);
    $progressColumn = $roadmapConfig['progress_column'] ?? '';
    $hasProgress    = $progressColumn !== '' && isset($tableConfig['columns'][$progressColumn]);

    $categories = [];
    if ($hasCategory) {
        $categoryDefinition = $tableConfig['columns'][$categoryColumn];
        $categoryType = strtolower($categoryDefinition['type'] ?? '');
        $enumColors   = [];
        if (is_array($categoryDefinition['enum_colors'] ?? null)) {
            $enumColors = $categoryDefinition['enum_colors'];
        }
        if ($categoryType === 'enum' && is_array($categoryDefinition['options'] ?? null)) {
            foreach ($categoryDefinition['options'] as $option) {
                $categoryValue = (string)$option;
                $categories[] = [
                    'value' => $categoryValue,
                    'label' => $categoryValue,
                    'color' => $enumColors[$categoryValue] ?? $defaultColor,
                ];
            }
        }
    }

    $barColumns = [];
    foreach (($roadmapConfig['card_columns'] ?? []) as $column) {
        if (
            is_string($column) && isset($tableConfig['columns'][$column])
            && $column !== $startColumn && $column !== $endColumn
        ) {
            $barColumns[] = $column;
        }
    }

    $selectColumns = array_values(array_unique(array_merge(
        [$idColumn, $startColumn, $endColumn, $titleColumn],
        $hasCategory ? [$categoryColumn] : [],
        $hasProgress ? [$progressColumn] : [],
        $barColumns
    )));
    $selectSql = implode(', ', array_map(fn($column) => pg_ident($column), $selectColumns));

    $rowParameters = [];
    $rowWhere  = ' WHERE ' . pg_ident($startColumn) . ' IS NOT NULL AND ' . pg_ident($endColumn) . ' IS NOT NULL';
    if (!empty($tableConfig['owner_restricted'])) {
        $rowWhere .= owner_restriction_sql('_t.' . pg_ident($idColumn), 1, 2);
        $rowParameters = [$table, $context->userId];
    }

    $sql = sprintf(
        'SELECT %s FROM %s.%s AS _t%s ORDER BY %s ASC',
        $selectSql,
        pg_ident($schemaName),
        pg_ident($table),
        $rowWhere,
        pg_ident($startColumn)
    );
    $result = @pg_query_params($conn, $sql, $rowParameters);
    $rows = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        pg_free_result($result);
    }
    $rows = map_fk_display($schema, $tableConfig, $rows, $conn);

    if ($hasCategory && $categories === []) {
        $categoryColorValues = [];
        foreach ($rows as $row) {
            $categoryValue = (string)($row[$categoryColumn] ?? '');
            if ($categoryValue !== '' && !isset($categoryColorValues[$categoryValue])) {
                $categoryColorValues[$categoryValue] = true;
                $categories[] = ['value' => $categoryValue, 'label' => $categoryValue, 'color' => $defaultColor];
            }
        }
    }

    $bars = [];
    foreach ($rows as $row) {
        $startDate = substr((string)($row[$startColumn] ?? ''), 0, 10);
        $endDate   = substr((string)($row[$endColumn] ?? ''), 0, 10);
        if ($startDate === '' || $endDate === '') {
            continue;
        }

        $fields = [];
        foreach ($barColumns as $column) {
            $label = $tableConfig['columns'][$column]['display_name'] ?? $column;
            $value = $row[$column . '__display'] ?? $row[$column] ?? '';
            if ($value === null || $value === '') {
                continue;
            }
            $fields[] = ['label' => $label, 'value' => (string)$value];
        }

        $categoryValue = '';
        $categoryColor = $defaultColor;
        if ($hasCategory) {
            $categoryValue = (string)($row[$categoryColumn] ?? '');
            foreach ($categories as $category) {
                if ($category['value'] === $categoryValue) {
                    $categoryColor = $category['color'];
                    break;
                }
            }
        }

        $progress = null;
        if ($hasProgress) {
            $progressValue = $row[$progressColumn] ?? null;
            if ($progressValue !== null && $progressValue !== '') {
                $progress = max(0, min(100, (int)round((float)$progressValue)));
            }
        }

        $bars[] = [
            'id'       => $row[$idColumn],
            'title'    => $row[$titleColumn . '__display'] ?? $row[$titleColumn] ?? ('#' . $row[$idColumn]),
            'start'    => $startDate,
            'end'      => $endDate,
            'category' => $categoryValue,
            'color'    => $categoryColor,
            'progress' => $progress,
            'milestone' => $startDate === $endDate,
            'fields'   => $fields,
            'rowData'  => $row,
        ];
    }

    $meta['configured']     = true;
    $meta['table']           = $table;
    $meta['start_column']    = $startColumn;
    $meta['end_column']      = $endColumn;
    $meta['title_column']    = $titleColumn;
    $meta['default_color']   = $defaultColor;
    $categoryLabel = '';
    if ($hasCategory) {
        $categoryLabel = $tableConfig['columns'][$categoryColumn]['display_name'] ?? $categoryColumn;
    }
    $meta['category_label'] = $categoryLabel;
    $meta['table_label']     = $tableConfig['display_name'] ?? $table;
    $meta['categories']      = $categories;
    $meta['rows']            = $bars;
    throw ResponseException::encoded($meta);
}
