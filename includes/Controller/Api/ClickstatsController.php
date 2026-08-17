<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ResponseException;
use App\Service\AppContext;

final class ClickstatsController
{
    private const MAX_EVENTS = 50;

    private const MAX_ELEMENT = 120;

    private const MAX_PAGE    = 120;

    private const MAX_TABLE   = 100;

    private const MAX_RECORD_ID = 2147483647;

    private const MAX_ROWS_PER_MIN = 300;

    public function __construct(private readonly AppContext $context)
    {
    }

    public function handle(): void
    {
        if ($this->context->request()->method() !== 'POST') {
            $this->done(405);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->done(400);
        }

        os_require_csrf('body', $payload);
        require_not_demo();

        $cfg = clickstats_settings();
        if (!$cfg['enabled']) {
            $this->done();
        }

        $events = $payload['events'] ?? null;
        if (!is_array($events) || $events === []) {
            $this->done();
        }

        $userId       = $this->context->session()->userId();
        $trackRecords = !empty($cfg['track_records']);

        $params       = [];
        $placeholders = [];

        $budget = $this->budget(min(count($events), self::MAX_EVENTS));
        if ($budget <= 0) {
            $this->done();
        }

        foreach (array_slice($events, 0, $budget) as $input) {
            if (!is_array($input)) {
                continue;
            }
            $element = $this->text($input['element'] ?? null);
            if ($element === '') {
                continue;
            }

            $table    = null;
            $recordId = null;
            if ($trackRecords) {
                $candidate = $this->text($input['table'] ?? null);

                if ($candidate !== '' && user_can_access('tables', $candidate)) {
                    $table = mb_substr($candidate, 0, self::MAX_TABLE);

                    $recordId = filter_var(
                        $input['record_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => self::MAX_RECORD_ID]]
                    );
                    if ($recordId === false) {
                        $recordId = null;
                    }
                }
            }

            $page = $this->text($input['page'] ?? null);

            $base = count($params);
            $placeholders[] = '($' . ($base + 1) . ', $' . ($base + 2) . ', $' . ($base + 3)
                . ', $' . ($base + 4) . ', $' . ($base + 5) . ')';
            array_push(
                $params,
                $userId,
                mb_substr($element, 0, self::MAX_ELEMENT),
                $page === '' ? null : mb_substr($page, 0, self::MAX_PAGE),
                $table,
                $recordId
            );
        }

        if ($placeholders === []) {
            $this->done();
        }

        $conn   = $this->context->connection();
        $target = sys_table('clickstats');
        $result    = @pg_query_params(
            $conn,
            "INSERT INTO {$target} (user_id, element, page, table_name, record_id) VALUES "
            . implode(', ', $placeholders),
            $params
        );
        if (!$result) {
            error_log('[OpenSparrow] clickstats insert failed: ' . pg_last_error($conn));
            $this->done(500);
        }

        $this->done();
    }

    private function done(int $code = 204): never
    {
        http_response_code($code);
        throw ResponseException::sent();
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function budget(int $wanted): int
    {
        $now     = time();
        $stored  = $this->context->session()->get('clickstats_window');
        $window  = [];
        $used    = 0;
        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (!is_array($entry) || ($now - (int) ($entry[0] ?? 0)) >= 60) {
                continue;
            }
            $window[] = $entry;
            $used    += (int) ($entry[1] ?? 0);
        }

        $take = max(0, min($wanted, self::MAX_ROWS_PER_MIN - $used));
        if ($take > 0) {
            $window[] = [$now, $take];
        }
        $this->context->session()->set('clickstats_window', $window);

        return $take;
    }
}
