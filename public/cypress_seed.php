<?php

// cypress_seed.php — Development-only test-data seeder for Cypress E2E tests.
// SECURITY: dead on production — hard APP_ENV guard (404) runs before the token check,
// so an accidental deployment exposes nothing (APP_ENV defaults to 'production' in includes/config.php).
// Second gate: shared token (CYPRESS_SEED_TOKEN env, default 'cypress-dev-seed') compared with hash_equals
// actions: seed/users — upserts fixed test users (Argon2id hashes) into sys_table('users'); returns JSON results

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

// Hard environment guard: APP_ENV defaults to 'production' when unset, so this
// endpoint only comes alive when the operator explicitly opts into a dev
// environment (docker-compose.override.yml sets APP_ENV=development). A 404
// with no body mirrors a missing file — existence is not disclosed.
if (APP_ENV === 'production') {
    http_response_code(404);
    exit;
}

// Token guard: prevents accidental or malicious triggering.
// Override with CYPRESS_SEED_TOKEN env var for CI.
$expectedToken = getenv('CYPRESS_SEED_TOKEN') ?: 'cypress-dev-seed';
$providedToken = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid seed token']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

header('Content-Type: application/json');

try {
    $conn  = db_connect();
    $tUsers = sys_table('users');
    $action = $_POST['action'] ?? $_GET['action'] ?? 'seed';
    $results = [];

    // ── Upsert test users ─────────────────────────────────────────────────────
    if ($action === 'seed' || $action === 'users') {
        $argonOpts = ['memory_cost' => 1 << 16, 'time_cost' => 2, 'threads' => 1];
        $optsJson  = json_encode($argonOpts);

        foreach ([
            ['test',      'test',      'editor'],
            ['testadmin', 'testadmin', 'admin'],
        ] as [$username, $password, $role]) {
            $salt = bin2hex(random_bytes(32));
            $hash = password_hash($salt . $password, PASSWORD_ARGON2ID, $argonOpts);

            $res = pg_query_params($conn, "
                INSERT INTO $tUsers (username, password_hash, salt, password_algo, password_params, is_active, role)
                VALUES (\$1, \$2, \$3, 'argon2id', \$4, true, \$5)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash,
                    salt          = EXCLUDED.salt,
                    is_active     = true,
                    role          = EXCLUDED.role
            ", [$username, $hash, $salt, $optsJson, $role]);

            $results[$username] = $res ? 'ok' : pg_last_error($conn);
        }
    }

    // ── Clean up cypress-created application records ──────────────────────────
    if ($action === 'seed' || $action === 'cleanup') {
        $schemaPath = __DIR__ . '/../config/schema.json';
        if (file_exists($schemaPath)) {
            $schema = json_decode(file_get_contents($schemaPath), true) ?? [];
            $appSchema = pg_ident(sys_schema());
            $cleaned = [];

            foreach ($schema['tables'] ?? [] as $tableName => $tableCfg) {
                // Find first text-like column to match cypress-prefixed values against
                $textCol = null;
                foreach ($tableCfg['columns'] ?? [] as $colName => $colCfg) {
                    if ($colName === 'id') {
                        continue;
                    }
                    $type = strtolower($colCfg['type'] ?? '');
                    if (in_array($type, ['text', 'varchar', 'character varying', 'string', ''], true)) {
                        $textCol = $colName;
                        break;
                    }
                }

                if ($textCol === null) {
                    continue;
                }

                $pgTable = $appSchema . '.' . pg_ident($tableName);
                $pgCol   = pg_ident($textCol);

                $res = @pg_query($conn, "DELETE FROM $pgTable WHERE $pgCol ILIKE 'cypress%' OR $pgCol ILIKE 'cy-%'");
                if ($res) {
                    $deleted = pg_affected_rows($res);
                    if ($deleted > 0) {
                        $cleaned[$tableName] = $deleted;
                    }
                }
            }

            $results['cleaned'] = $cleaned;
        }
    }

    echo json_encode(['status' => 'ok', 'results' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
