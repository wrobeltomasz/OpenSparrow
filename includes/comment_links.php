<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

function comment_record_link_pattern(): string
{
    return '/\[Record:\s*([a-zA-Z0-9_]+):(\d+)\]/';
}

function comment_extract_record_links(string $body): array
{
    $pairs = [];
    if (!preg_match_all(comment_record_link_pattern(), $body, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $recordId = (int) $match[2];
        if ($recordId > 0) {
            $pairs[$match[1]][$recordId] = true;
        }
    }
    return $pairs;
}
