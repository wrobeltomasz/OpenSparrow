<?php

// cron/cron_notifications.php — CLI cron: calendar-based in-app notifications + automation email delivery
// Reads the "calendar" config sources and inserts spw_users_notifications rows (daily de-duplication)
// Also delivers pending rows from spw_automation_emails (queued by the "email" automation action) via mail();
// requires AUTOMATION_EMAIL_FROM, retries up to AUTOMATION_EMAIL_MAX_ATTEMPTS, header-injection safe
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// Disable output buffering to force real-time rendering in the browser
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Fix for PHP 8 strict typing: ob_implicit_flush requires a boolean parameter
ob_implicit_flush(true);

// Helper function to print logs in real-time
function print_log(string $msg): void
{

    echo $msg . "<br>\n";
// Pad with empty spaces to bypass internal browser buffers
    echo str_pad('', 4096) . "\n";
    flush();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

function cron_mysql_bt(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}

function cron_mysql_pdo(): ?\PDO
{
    if (MYSQL_HOST === '' || MYSQL_DB === '' || MYSQL_USER === '') {
        return null;
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=%d',
            MYSQL_HOST,
            MYSQL_PORT,
            MYSQL_DB,
            MYSQL_CONNECT_TIMEOUT
        );
        return new \PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => MYSQL_CONNECT_TIMEOUT,
        ]);
    } catch (\PDOException $e) {
        error_log('[cron][mysql] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
        return null;
    }
}

$triggeredBy = (isset($argv[1]) && $argv[1] === 'admin') ? 'admin' : 'cron';
print_log("<h3>Start CRON - Diagnostics</h3>");
require_once __DIR__ . '/../includes/config_store.php';
$config = config_get('calendar');
if ($config === null) {
    print_log("<span style='color:red;'>Missing calendar configuration</span>");
    exit;
}

if (empty($config['sources'])) {
    print_log("<span style='color:red;'>No sources defined in calendar.</span>");
    exit;
}

print_log("Loaded calendar configuration. Number of sources: " . count($config['sources']) . "<br>");

// Table → PG schema map from schema.json; tables without an explicit schema
// (or missing from schema.json) fall back to the system schema.
$schemaCfg    = config_get('schema') ?? [];
$schemaTables = is_array($schemaCfg['tables'] ?? null) ? $schemaCfg['tables'] : [];

$mysqlGatewayPath   = __DIR__ . '/../config/mysql_gateway.json';
$mysqlGatewayTables = [];
if (file_exists($mysqlGatewayPath)) {
    $mgCfg              = json_decode(file_get_contents($mysqlGatewayPath), true);
    $mysqlGatewayTables = is_array($mgCfg) ? ($mgCfg['mysql_tables'] ?? []) : [];
}
try {
    print_log("Connecting to the database...");
    $conn = db_connect();
    print_log("Database connected successfully.<br><hr>");

    // Purge login attempts older than 30 days to prevent unbounded table growth.
    pg_query($conn, "DELETE FROM " . sys_table('login_attempts') . " WHERE attempted_at < NOW() - INTERVAL '30 days'");

    $tCronLog = sys_table('users_notifications_log');
    $logRes = pg_query_params($conn, "INSERT INTO $tCronLog (triggered_by) VALUES ($1) RETURNING id", [$triggeredBy]);
    $logId = $logRes ? (int) pg_fetch_result($logRes, 0, 0) : null;
    $insertedCount = 0;
    $sourcesProcessed = 0;
    foreach ($config['sources'] as $source) {
        $table = $source['table'] ?? '';
        $dateCol = $source['date_column'] ?? '';
        $titleCol = $source['title_column'] ?? '';
        $notifiedUsers = $source['notified_users'] ?? [];
        $days = (int)($source['notify_before_days'] ?? 0);
        $urlTemplate = $source['url_template'] ?? '';
    // Check if all required fields and at least one user are present
        if (!$table || !$dateCol || !$titleCol || empty($notifiedUsers) || !is_array($notifiedUsers)) {
            print_log("Skipping source <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b> (missing required columns or no users assigned).");
            continue;
        }
        $sourcesProcessed++;

        $targetDate = date('Y-m-d', strtotime("+$days days"));
        print_log(
            "Analyzing table: <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b>"
            . " (looking for date: <b>" . htmlspecialchars($targetDate, ENT_QUOTES, 'UTF-8') . "</b>"
            . " in column <b>" . htmlspecialchars($dateCol, ENT_QUOTES, 'UTF-8') . "</b>)"
        );
    // Fetch records — branch on MySQL gateway vs native PostgreSQL
        if (in_array($table, $mysqlGatewayTables, true)) {
            $pdo = cron_mysql_pdo();
            if ($pdo === null) {
                print_log(
                    "<span style='color:orange;'>Skipping MySQL table <b>"
                    . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                    . "</b> — MySQL Gateway not configured.</span>"
                );
                continue;
            }
            $mysqlPk = (string)($schemaTables[$table]['mysql_pk'] ?? 'id');
            $sql     = sprintf(
                'SELECT %s AS record_id, %s AS title FROM %s.%s WHERE DATE(%s) = ?',
                cron_mysql_bt($mysqlPk),
                cron_mysql_bt($titleCol),
                cron_mysql_bt(MYSQL_DB),
                cron_mysql_bt($table),
                cron_mysql_bt($dateCol)
            );
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$targetDate]);
                $rows = $stmt->fetchAll();
            } catch (\PDOException $e) {
                print_log(
                    "<span style='color:red;'>MySQL QUERY ERROR: "
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                    . "</span>"
                );
                continue;
            }
        } else {
            $tableSchema = (string)($schemaTables[$table]['schema'] ?? sys_schema());
            $sql         = sprintf(
                'SELECT id AS record_id, %s AS title FROM %s.%s WHERE DATE(%s) = $1',
                pg_ident($titleCol),
                pg_ident($tableSchema),
                pg_ident($table),
                pg_ident($dateCol)
            );
            $result = pg_query_params($conn, $sql, [$targetDate]);
            if (!$result) {
                print_log("<span style='color:red;'>SQL QUERY ERROR: " . htmlspecialchars(pg_last_error($conn), ENT_QUOTES, 'UTF-8') . "</span>");
                continue;
            }
            $rows = pg_fetch_all($result) ?: [];
        }

        // Resolve only user IDs that actually exist and are active
        $uidList = '{' . implode(',', array_map('intval', $notifiedUsers)) . '}';
        $validRes = pg_query_params($conn, "SELECT id FROM " . sys_table('users') . " WHERE id = ANY($1::int[]) AND is_active = TRUE", [$uidList]);
        $validUserIds = $validRes ? array_map('intval', array_column(pg_fetch_all($validRes) ?: [], 'id')) : [];
        if (empty($validUserIds)) {
            print_log("Skipping source <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b> (none of the configured users exist or are active).");
            continue;
        }

        $rowCount = count($rows);
        print_log("Found matching records in database: <b>$rowCount</b>");
        foreach ($rows as $row) {
            $recordId = (int)$row['record_id'];
        // Prepend target date to the title
            $titleText = $targetDate . ": " . $row['title'];
            $link = str_replace('{id}', (string)$recordId, $urlTemplate);
        // Insert a notification for every valid user
            foreach ($validUserIds as $userId) {
                $userId = (int)$userId;
                $insertSql = "
                    INSERT INTO " . sys_table('users_notifications') . " (user_id, title, link, source_table, source_id, notify_date)
                    VALUES ($1, $2, $3, $4, $5, $6)
                    ON CONFLICT (user_id, source_table, source_id, notify_date) DO NOTHING
                ";
                $res = pg_query_params($conn, $insertSql, [$userId, $titleText, $link, $table, $recordId, $targetDate]);
                if ($res && pg_affected_rows($res) > 0) {
                    print_log("&nbsp;&nbsp; Added notification for user ID $userId (Record ID: $recordId)");
                    $insertedCount++;
                } else {
                    print_log("&nbsp;&nbsp; Skipped (Notification for user $userId for record $recordId already exists).");
                }
            }
        }
        print_log("<hr>");
    }

    // ── Automation email queue (spw_automation_emails) ──────────────────────
    // Rows are queued by the "email" automation action (includes/automations.php)
    // and delivered here so record writes never block on SMTP.
    print_log("<h3>Automation email queue</h3>");
    $tAutoEmails = sys_table('automation_emails');
    $emailsSent  = 0;
    $emailsFailed = 0;
    if (AUTOMATION_EMAIL_FROM === '') {
        print_log("<span style='color:orange;'>AUTOMATION_EMAIL_FROM is not configured — skipping email delivery (queued emails stay pending).</span>");
    } else {
        $pendRes = pg_query_params(
            $conn,
            "SELECT id, recipient, subject, body FROM $tAutoEmails
             WHERE status = 'pending' AND attempts < \$1
             ORDER BY id ASC LIMIT \$2",
            [AUTOMATION_EMAIL_MAX_ATTEMPTS, AUTOMATION_EMAIL_BATCH_LIMIT]
        );
        $pending = $pendRes ? (pg_fetch_all($pendRes) ?: []) : [];
        print_log("Pending emails picked up: <b>" . count($pending) . "</b>");

        // Strip CR/LF so user-templated values cannot inject extra mail headers.
        $hdrSafe = static fn(string $s): string => str_replace(["\r", "\n"], ' ', $s);

        foreach ($pending as $mailRow) {
            $mailId    = (int) $mailRow['id'];
            $recipient = $hdrSafe((string) $mailRow['recipient']);
            $subject   = $hdrSafe((string) $mailRow['subject']);
            $headers   = 'From: ' . $hdrSafe(AUTOMATION_EMAIL_FROM) . "\r\n"
                       . "MIME-Version: 1.0\r\n"
                       . "Content-Type: text/plain; charset=UTF-8\r\n"
                       . "Content-Transfer-Encoding: 8bit";

            $ok = @mail(
                $recipient,
                '=?UTF-8?B?' . base64_encode($subject) . '?=',
                (string) $mailRow['body'],
                $headers
            );

            if ($ok) {
                pg_query_params(
                    $conn,
                    "UPDATE $tAutoEmails SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, error_msg = NULL WHERE id = \$1",
                    [$mailId]
                );
                $emailsSent++;
                print_log("&nbsp;&nbsp; Sent email #$mailId to " . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8'));
            } else {
                // Mark as error once the attempt budget is exhausted; otherwise retry next run.
                pg_query_params(
                    $conn,
                    "UPDATE $tAutoEmails
                     SET attempts = attempts + 1,
                         error_msg = \$2,
                         status = CASE WHEN attempts + 1 >= \$3 THEN 'error' ELSE 'pending' END
                     WHERE id = \$1",
                    [$mailId, 'mail() returned false', AUTOMATION_EMAIL_MAX_ATTEMPTS]
                );
                $emailsFailed++;
                print_log("<span style='color:red;'>&nbsp;&nbsp; Failed email #$mailId to " . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8') . "</span>");
            }
        }
        print_log("Emails sent: <b>$emailsSent</b>, failed this run: <b>$emailsFailed</b>");
    }
    print_log("<hr>");

    print_log("<h3>Finished. NEW notifications generated: $insertedCount</h3>");
    if ($logId) {
        pg_query_params(
            $conn,
            "UPDATE $tCronLog SET status='success', finished_at=NOW(), sources_processed=$1, notifications_created=$2 WHERE id=$3",
            [$sourcesProcessed, $insertedCount, $logId]
        );
    }
} catch (Throwable $e) {
    print_log("<span style='color:red;'>Critical error: " . htmlspecialchars($e->getMessage()) . "</span>");
    if (!empty($logId) && !empty($conn)) {
        pg_query_params(
            $conn,
            "UPDATE $tCronLog SET status='error', finished_at=NOW(), error_message=$1 WHERE id=$2",
            [substr($e->getMessage(), 0, 2000), $logId]
        );
    }
}
