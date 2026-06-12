<?php

// cron/cron_notifications.php
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
$triggeredBy = (isset($argv[1]) && $argv[1] === 'admin') ? 'admin' : 'cron';
print_log("<h3>Start CRON - Diagnostics</h3>");
$configFile = __DIR__ . '/../config/calendar.json';
if (!file_exists($configFile)) {
    print_log("<span style='color:red;'>Missing calendar.json file</span>");
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
if (empty($config['sources'])) {
    print_log("<span style='color:red;'>No sources defined in calendar.</span>");
    exit;
}

print_log("Loaded calendar.json file. Number of sources: " . count($config['sources']) . "<br>");

// Table → PG schema map from schema.json; tables without an explicit schema
// (or missing from schema.json) fall back to the system schema.
$schemaFile   = __DIR__ . '/../config/schema.json';
$schemaTables = [];
if (file_exists($schemaFile)) {
    $schemaCfg    = json_decode(file_get_contents($schemaFile), true) ?? [];
    $schemaTables = is_array($schemaCfg['tables'] ?? null) ? $schemaCfg['tables'] : [];
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
    // Fetch records matching the date directly from the target table
        $tableSchema = (string)($schemaTables[$table]['schema'] ?? sys_schema());
        $sql = sprintf(
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

        // Resolve only user IDs that actually exist and are active
        $uidList = '{' . implode(',', array_map('intval', $notifiedUsers)) . '}';
        $validRes = pg_query_params($conn, "SELECT id FROM " . sys_table('users') . " WHERE id = ANY($1::int[]) AND is_active = TRUE", [$uidList]);
        $validUserIds = $validRes ? array_map('intval', array_column(pg_fetch_all($validRes) ?: [], 'id')) : [];
        if (empty($validUserIds)) {
            print_log("Skipping source <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b> (none of the configured users exist or are active).");
            continue;
        }

        $rowCount = pg_num_rows($result);
        print_log("Found matching records in database: <b>$rowCount</b>");
        while ($row = pg_fetch_assoc($result)) {
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
