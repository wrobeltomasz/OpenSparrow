<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if ($argc < 3) {
    fwrite(STDERR, "usage: php scripts/baseline/compare.php before.json after.json\n");
    exit(2);
}

$before = json_decode((string) file_get_contents($argv[1]), true);
$after  = json_decode((string) file_get_contents($argv[2]), true);

if (!is_array($before) || !is_array($after)) {
    fwrite(STDERR, "both arguments must be snapshots written by record.php\n");
    exit(2);
}

$beforeScenarios = $before['scenarios'] ?? [];
$afterScenarios  = $after['scenarios'] ?? [];

$differences = [];

foreach ($beforeScenarios as $id => $expected) {
    if (!isset($afterScenarios[$id])) {
        $differences[$id] = ['missing in ' . $argv[2]];
        continue;
    }

    $actual = $afterScenarios[$id];
    $fields = [];

    foreach (['status', 'contentType', 'location', 'body'] as $field) {
        if (($expected[$field] ?? null) === ($actual[$field] ?? null)) {
            continue;
        }
        if ($field === 'body') {
            $expectedLines = explode("\n", (string) ($expected[$field] ?? ''));
            $actualLines   = explode("\n", (string) ($actual[$field] ?? ''));
            $firstChange   = 0;
            while (
                isset($expectedLines[$firstChange], $actualLines[$firstChange])
                && $expectedLines[$firstChange] === $actualLines[$firstChange]
            ) {
                $firstChange++;
            }
            $fields[] = sprintf(
                "body differs at line %d\n      before: %s\n      after:  %s",
                $firstChange + 1,
                substr($expectedLines[$firstChange] ?? '<eof>', 0, 160),
                substr($actualLines[$firstChange] ?? '<eof>', 0, 160)
            );
            continue;
        }
        $fields[] = sprintf(
            '%s: %s -> %s',
            $field,
            var_export($expected[$field] ?? null, true),
            var_export($actual[$field] ?? null, true)
        );
    }

    if ($fields !== []) {
        $differences[$id] = $fields;
    }
}

foreach ($afterScenarios as $id => $actual) {
    if (!isset($beforeScenarios[$id])) {
        $differences[$id] = ['new in ' . $argv[2]];
    }
}

if ($differences === []) {
    fwrite(STDOUT, count($beforeScenarios) . " scenarios identical\n");
    exit(0);
}

foreach ($differences as $id => $fields) {
    fwrite(STDOUT, $id . "\n");
    foreach ($fields as $field) {
        fwrite(STDOUT, '    ' . $field . "\n");
    }
}

fwrite(STDOUT, "\n" . count($differences) . ' of ' . count($beforeScenarios) . " scenarios differ\n");
exit(1);
