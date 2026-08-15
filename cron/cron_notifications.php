<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/../includes/exception_handler.php';

use App\Exception\ControlFlowException;
use App\Exception\ForbiddenException;

if (php_sapi_name() !== 'cli') {
    os_register_exception_handler('html');
    throw new ForbiddenException('This script may only be run from the command line.');
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_implicit_flush(true);

function print_log(string $message): void
{
    echo $message . "<br>\n";

    echo str_pad('', 4096) . "\n";
    flush();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

function cron_notifications_main(array $argv): int
{
    $triggeredBy = (isset($argv[1]) && $argv[1] === 'admin') ? 'admin' : 'cron';
    print_log("<h3>Start CRON - Diagnostics</h3>");
    require_once __DIR__ . '/../includes/config_store.php';

    try {
        require_once __DIR__ . '/../includes/clickstats.php';
        $purged = clickstats_purge_expired(db_connect());
        if ($purged !== null) {
            print_log("Click statistics retention: removed {$purged} expired row(s).");
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        error_log('[cron_notifications] clickstats retention failed: ' . $exception->getMessage());
    }

    $config = config_get('calendar');
    if ($config === null) {
        print_log("<span style='color:red;'>Missing calendar configuration</span>");
        return 0;
    }

    if (empty($config['sources'])) {
        print_log("<span style='color:red;'>No sources defined in calendar.</span>");
        return 0;
    }

    print_log("Loaded calendar configuration. Number of sources: " . count($config['sources']) . "<br>");

    $schemaCfg    = config_get('schema') ?? [];
    $schemaTables = is_array($schemaCfg['tables'] ?? null) ? $schemaCfg['tables'] : [];

    $cronLogTable = sys_table('users_notifications_log');

    try {
        print_log("Connecting to the database...");
        $conn = db_connect();
        print_log("Database connected successfully.<br><hr>");

        pg_query(
            $conn,
            "DELETE FROM " . sys_table('login_attempts') . " WHERE attempted_at < NOW() - INTERVAL '30 days'"
        );

        $logResult = pg_query_params(
            $conn,
            "INSERT INTO $cronLogTable (triggered_by) VALUES ($1) RETURNING id",
            [$triggeredBy]
        );
        $logId = $logResult ? (int) pg_fetch_result($logResult, 0, 0) : null;
        $insertedCount = 0;
        $sourcesProcessed = 0;
        foreach ($config['sources'] as $source) {
            $table = $source['table'] ?? '';
            $dateColumn = $source['date_column'] ?? '';
            $titleColumn = $source['title_column'] ?? '';
            $notifiedUsers = $source['notified_users'] ?? [];
            $days = (int)($source['notify_before_days'] ?? 0);
            $urlTemplate = $source['url_template'] ?? '';

            if (!$table || !$dateColumn || !$titleColumn || empty($notifiedUsers) || !is_array($notifiedUsers)) {
                print_log(
                    "Skipping source <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                    . "</b> (missing required columns or no users assigned)."
                );
                continue;
            }
            $sourcesProcessed++;

            $targetDate = date('Y-m-d', strtotime("+$days days"));
            print_log(
                "Analyzing table: <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b>"
                . " (looking for date: <b>" . htmlspecialchars($targetDate, ENT_QUOTES, 'UTF-8') . "</b>"
                . " in column <b>" . htmlspecialchars($dateColumn, ENT_QUOTES, 'UTF-8') . "</b>)"
            );

            $tableSchema = (string)($schemaTables[$table]['schema'] ?? sys_schema());
            $sql         = sprintf(
                'SELECT id AS record_id, %s AS title FROM %s.%s WHERE DATE(%s) = $1',
                pg_ident($titleColumn),
                pg_ident($tableSchema),
                pg_ident($table),
                pg_ident($dateColumn)
            );
            $result = pg_query_params($conn, $sql, [$targetDate]);
            if (!$result) {
                print_log(
                    "<span style='color:red;'>SQL QUERY ERROR: "
                    . htmlspecialchars(pg_last_error($conn), ENT_QUOTES, 'UTF-8') . "</span>"
                );
                continue;
            }
            $rows = pg_fetch_all($result) ?: [];

            $uidList = '{' . implode(',', array_map('intval', $notifiedUsers)) . '}';
            $validationResult = pg_query_params(
                $conn,
                "SELECT id FROM " . sys_table('users') . " WHERE id = ANY($1::int[]) AND is_active = TRUE",
                [$uidList]
            );
            $validUserIds = $validationResult
                ? array_map('intval', array_column(pg_fetch_all($validationResult) ?: [], 'id'))
                : [];
            if (empty($validUserIds)) {
                print_log(
                    "Skipping source <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                    . "</b> (none of the configured users exist or are active)."
                );
                continue;
            }

            $rowCount = count($rows);
            print_log("Found matching records in database: <b>$rowCount</b>");
            foreach ($rows as $row) {
                $recordId = (int)$row['record_id'];

                $titleText = $targetDate . ": " . $row['title'];
                $link = str_replace('{id}', (string)$recordId, $urlTemplate);

                foreach ($validUserIds as $userId) {
                    $userId = (int)$userId;
                    $insertSql = "
                        INSERT INTO " . sys_table('users_notifications') . " (
                            user_id, title, link, source_table, source_id, notify_date
                        )
                        VALUES ($1, $2, $3, $4, $5, $6)
                        ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING
                    ";
                    $updateResult = pg_query_params(
                        $conn,
                        $insertSql,
                        [$userId, $titleText, $link, $table, $recordId, $targetDate]
                    );
                    if ($updateResult && pg_affected_rows($updateResult) > 0) {
                        print_log("&nbsp;&nbsp; Added notification for user ID $userId (Record ID: $recordId)");
                        $insertedCount++;
                    } else {
                        print_log(
                            "&nbsp;&nbsp; Skipped (Notification for user $userId for record $recordId already exists)."
                        );
                    }
                }
            }
            print_log("<hr>");
        }

        print_log("<h3>Note reminders</h3>");
        $today = date('Y-m-d');
        $noteResult = pg_query(
            $conn,
            "SELECT id, user_id, body, related_table, related_id, reminder_date::date AS reminder_day
             FROM " . sys_table('notes') . "
             WHERE reminder_date IS NOT NULL AND reminder_date <= NOW() AND deleted_at IS NULL"
        );
        $noteRows = $noteResult ? (pg_fetch_all($noteResult) ?: []) : [];
        print_log("Notes with a reminder due: <b>" . count($noteRows) . "</b>");
        foreach ($noteRows as $note) {
            $noteUserId = (int)$note['user_id'];
            $noteTitle  = mb_strimwidth((string)$note['body'], 0, 120, '...');
            $noteLink   = ($note['related_table'] && $note['related_id'])
                ? 'edit.php?table=' . rawurlencode((string)$note['related_table']) . '&id=' . (int)$note['related_id']
                : '';
            $noteInsertSql = "
                INSERT INTO " . sys_table('users_notifications') . " (
                    user_id, title, link, source_table, source_id, notify_date
                )
                VALUES ($1, $2, $3, 'notes', $4, $5)
                ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING
            ";

            $noteDay = $note['reminder_day'] ?: $today;
            $noteParams = [$noteUserId, $noteTitle, $noteLink, (int)$note['id'], $noteDay];
            $noteInsertResult = pg_query_params($conn, $noteInsertSql, $noteParams);
            if ($noteInsertResult && pg_affected_rows($noteInsertResult) > 0) {
                print_log("&nbsp;&nbsp; Added reminder for user ID $noteUserId (Note ID: " . (int)$note['id'] . ")");
                $insertedCount++;
            }
        }
        print_log("<hr>");

        print_log("<h3>Automation email queue</h3>");
        $automationEmailsTable = sys_table('automation_emails');
        $emailsSent  = 0;
        $emailsFailed = 0;

        $appSettings = config_get('settings') ?? [];
        $smtpEnabled = !empty($appSettings['smtp_enabled']);
        if ($smtpEnabled) {
            require_once __DIR__ . '/../includes/smtp_client.php';
            require_once __DIR__ . '/../includes/crypto.php';
        }
        $smtpPassword = '';
        if ($smtpEnabled) {
            $smtpPassword = (string) (secret_decrypt((string) ($appSettings['smtp_password_enc'] ?? '')) ?? '');
        }
        $smtpConfig = [
            'host'       => (string) ($appSettings['smtp_host'] ?? ''),
            'port'       => (int) ($appSettings['smtp_port'] ?? 587),
            'encryption' => (string) ($appSettings['smtp_encryption'] ?? 'tls'),
            'username'   => (string) ($appSettings['smtp_username'] ?? ''),
            'password'   => $smtpPassword,
            'from'       => AUTOMATION_EMAIL_FROM,
        ];

        if (AUTOMATION_EMAIL_FROM === '') {
            print_log(
                "<span style='color:orange;'>AUTOMATION_EMAIL_FROM is not configured — "
                . "skipping email delivery (queued emails stay pending).</span>"
            );
        } elseif ($smtpEnabled && $smtpConfig['host'] === '') {
            print_log(
                "<span style='color:orange;'>SMTP delivery is enabled but no SMTP host is configured — "
                . "skipping email delivery (queued emails stay pending).</span>"
            );
        } else {
            $methodLabel = $smtpEnabled
                ? 'SMTP (' . htmlspecialchars($smtpConfig['host'], ENT_QUOTES, 'UTF-8') . ')'
                : 'PHP mail()';
            print_log('Delivery method: <b>' . $methodLabel . '</b>');
            $pendingResult = pg_query_params(
                $conn,
                "SELECT id, recipient, subject, body FROM $automationEmailsTable
                 WHERE status = 'pending' AND attempts < \$1
                 ORDER BY id ASC LIMIT \$2",
                [AUTOMATION_EMAIL_MAX_ATTEMPTS, AUTOMATION_EMAIL_BATCH_LIMIT]
            );
            $pending = $pendingResult ? (pg_fetch_all($pendingResult) ?: []) : [];
            print_log("Pending emails picked up: <b>" . count($pending) . "</b>");

            $hdrSafe = static fn(string $headerValue): string => str_replace(["\r", "\n"], ' ', $headerValue);

            foreach ($pending as $mailRow) {
                $mailId    = (int) $mailRow['id'];
                $recipient = $hdrSafe((string) $mailRow['recipient']);
                $subject   = $hdrSafe((string) $mailRow['subject']);

                if ($smtpEnabled) {
                    $result = smtp_send($smtpConfig, $recipient, $subject, (string) $mailRow['body']);
                    $success = $result['ok'];
                    $failReason = $result['error'] ?? 'SMTP delivery failed';
                } else {
                    $headers = 'From: ' . $hdrSafe(AUTOMATION_EMAIL_FROM) . "\r\n"
                             . "MIME-Version: 1.0\r\n"
                             . "Content-Type: text/plain; charset=UTF-8\r\n"
                             . "Content-Transfer-Encoding: 8bit";

                    $success = @mail(
                        $recipient,
                        '=?UTF-8?B?' . base64_encode($subject) . '?=',
                        (string) $mailRow['body'],
                        $headers
                    );
                    $failReason = 'mail() returned false';
                }

                if ($success) {
                    pg_query_params(
                        $conn,
                        "UPDATE $automationEmailsTable SET status = 'sent', sent_at = NOW(), "
                            . "attempts = attempts + 1, error_msg = NULL WHERE id = \$1",
                        [$mailId]
                    );
                    $emailsSent++;
                    print_log(
                        "&nbsp;&nbsp; Sent email #$mailId to "
                        . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8')
                    );
                } else {
                    pg_query_params(
                        $conn,
                        "UPDATE $automationEmailsTable
                         SET attempts = attempts + 1,
                             error_msg = \$2,
                             status = CASE WHEN attempts + 1 >= \$3 THEN 'error' ELSE 'pending' END
                         WHERE id = \$1",
                        [$mailId, $failReason, AUTOMATION_EMAIL_MAX_ATTEMPTS]
                    );
                    $emailsFailed++;
                    print_log(
                        "<span style='color:red;'>&nbsp;&nbsp; Failed email #$mailId to "
                        . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8') . ": "
                        . htmlspecialchars($failReason, ENT_QUOTES, 'UTF-8') . "</span>"
                    );
                }
            }
            print_log("Emails sent: <b>$emailsSent</b>, failed this run: <b>$emailsFailed</b>");
        }
        print_log("<hr>");

        print_log("<h3>Finished. NEW notifications generated: $insertedCount</h3>");
        if ($logId) {
            pg_query_params(
                $conn,
                "UPDATE $cronLogTable SET status='success', finished_at=NOW(), "
                    . "sources_processed=$1, notifications_created=$2 WHERE id=$3",
                [$sourcesProcessed, $insertedCount, $logId]
            );
        }
    } catch (ControlFlowException $signal) {
        throw $signal;
    } catch (Throwable $exception) {
        print_log("<span style='color:red;'>Critical error: " . htmlspecialchars($exception->getMessage()) . "</span>");
        if (!empty($logId) && !empty($conn)) {
            pg_query_params(
                $conn,
                "UPDATE $cronLogTable SET status='error', finished_at=NOW(), error_message=$1 WHERE id=$2",
                [substr($exception->getMessage(), 0, 2000), $logId]
            );
        }
    }

    return 0;
}

exit(cron_notifications_main($argv));
