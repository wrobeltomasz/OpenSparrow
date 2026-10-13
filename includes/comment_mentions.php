<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

use App\Exception\ControlFlowException;

function comment_mention_pattern(): string
{
    return '/(?<![a-zA-Z0-9_.-])@([a-zA-Z0-9_.-]{3,50})/';
}

function comment_extract_mentions(string $body): array
{
    if (!preg_match_all(comment_mention_pattern(), $body, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $mentions = [];
    foreach ($matches as $match) {
        $mentions[$match[1]] = true;
    }
    return array_keys($mentions);
}

function comment_mention_title(string $authorUsername): string
{
    return t('comments.mention_notification', ['user' => $authorUsername]);
}

function comment_mention_notify(
    PgSql\Connection $conn,
    string $relatedTable,
    int $relatedId,
    int $commentId,
    int $authorId,
    string $authorUsername,
    string $body
): void {
    $usernames = comment_extract_mentions($body);
    if ($usernames === [] || $authorUsername === '') {
        return;
    }

    try {
        $arrayLiteral = '{' . implode(',', $usernames) . '}';
        $usersResult = pg_query_params(
            $conn,
            'SELECT id FROM ' . sys_table('users')
            . ' WHERE username = ANY($1::text[]) AND is_active = TRUE AND id <> $2',
            [$arrayLiteral, $authorId]
        );
        if (!$usersResult) {
            error_log('[api_comments][mentions] user lookup failed: ' . pg_last_error($conn));
            return;
        }
        $targets = pg_fetch_all($usersResult) ?: [];
        if ($targets === []) {
            return;
        }

        $title = comment_mention_title($authorUsername);
        $link  = 'edit.php?table=' . rawurlencode($relatedTable) . '&id=' . $relatedId . '#tab-comments';
        $sql = 'INSERT INTO ' . sys_table('users_notifications')
            . ' (user_id, title, link, source_table, source_id, notify_date)'
            . ' VALUES ($1, $2, $3, $4, $5, CURRENT_DATE)'
            . ' ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING';

        foreach ($targets as $target) {
            $insertResult = pg_query_params($conn, $sql, [
                (int) $target['id'],
                $title,
                $link,
                'comments',
                $commentId,
            ]);
            if (!$insertResult) {
                error_log('[api_comments][mentions] notify failed: ' . pg_last_error($conn));
            }
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        error_log('[api_comments][mentions] notify failed: ' . $exception->getMessage());
    }
}
