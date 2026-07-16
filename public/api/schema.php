<?php

// api/schema.php — Public schema descriptor endpoint for the client UI (AJAX, GET-only)
// Auth gate: session + UA enforcement; GET + X-Requested-With required; no-store
// Returns a sanitised view of config/schema.json (tables, columns, FK reference_table, default_page_size); strips sensitive config
// Role-aware; read-only

require_once __DIR__ . '/../../includes/bootstrap.php';

// Read-only AJAX endpoint: auth + AJAX gates, no CSRF, no DB — data comes from schema.json
os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

// Prevent caching of the schema descriptor
header('Cache-Control: no-store, no-cache, must-revalidate');
// Restrict to GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$userRole = $_SESSION['role'] ?? 'viewer';
require_once __DIR__ . '/../../includes/config_store.php';
$schemaData = config_get('schema');
if (!is_array($schemaData) || !isset($schemaData['tables'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid schema format']);
    exit;
}

$publicSchema = [];
$includeHidden = ($_GET['include_hidden'] ?? '0') === '1';
foreach ($schemaData['tables'] as $tableName => $tableConfig) {
// Skip hidden tables unless caller explicitly requests them (e.g. workflow context)
    if (!$includeHidden && !empty($tableConfig['hidden'])) {
        continue;
    }

    $publicColumns = [];
    foreach ($tableConfig['columns'] as $colName => $colDef) {
    // Build minimal column object
        $pub = [
            'display_name'  => $colDef['display_name'] ?? $colName,
            'type'          => $colDef['type'] ?? 'text',
            'show_in_grid'  => $colDef['show_in_grid'] ?? true,
            'show_in_edit'  => $colDef['show_in_edit'] ?? true,
            'readonly'      => $colDef['readonly'] ?? false,
            'not_null'      => $colDef['not_null'] ?? false,
        ];
    // Send validation rules only to users with full access
        if ($userRole === 'editor') {
            if (!empty($colDef['validation_regexp'])) {
                $pub['validation_regexp'] = $colDef['validation_regexp'];
            }
            if (!empty($colDef['validation_message'])) {
                $pub['validation_message'] = $colDef['validation_message'];
            }
        }

        if (!empty($colDef['description'])) {
            $pub['description'] = $colDef['description'];
        }

        // Pass formula definition for virtual (computed) columns
        if (!empty($colDef['formula'])) {
            $pub['formula'] = $colDef['formula'];
        }

        // Keep dropdown options for UI
        if (!empty($colDef['options'])) {
            $pub['options'] = $colDef['options'];
        }
        if (!empty($colDef['enum_colors'])) {
            $pub['enum_colors'] = $colDef['enum_colors'];
        }

        $publicColumns[$colName] = $pub;
    }

    // Filter foreign keys
    $foreignKeys = [];
    if (!empty($tableConfig['foreign_keys'])) {
        foreach ($tableConfig['foreign_keys'] as $col => $fk) {
            $foreignKeys[$col] = [
                'display_column'   => $fk['display_column']   ?? 'id',
                'reference_table'  => $fk['reference_table']  ?? '',
                'reference_column' => $fk['reference_column'] ?? 'id',
                'display_columns'  => $fk['display_columns']  ?? [],
            ];
        }
    }

    $m2mList = [];
    foreach ($tableConfig['many_to_many'] ?? [] as $m2m) {
        $m2mList[] = [
            'label'          => $m2m['label']          ?? '',
            'junction_table' => $m2m['junction_table'] ?? '',
            'self_fk'        => $m2m['self_fk']        ?? '',
            'other_fk'       => $m2m['other_fk']       ?? '',
            'other_table'    => $m2m['other_table']    ?? '',
            'display_column' => $m2m['display_column'] ?? 'id',
        ];
    }

    $publicSchema[$tableName] = [
        'display_name' => $tableConfig['display_name'] ?? $tableName,
        'columns'      => $publicColumns,
        'icon'         => $tableConfig['icon'] ?? null,
        'foreign_keys' => $foreignKeys,
        'subtables'    => $tableConfig['subtables'] ?? [],
        'many_to_many' => $m2mList,
    ];
}

$pageSize = null;
if (isset($schemaData['default_page_size'])) {
    $ps = (int) $schemaData['default_page_size'];
    if (in_array($ps, [10, 25, 50, 100], true)) {
        $pageSize = $ps;
    }
}

$response = ['tables' => $publicSchema];
if ($pageSize !== null) {
    $response['default_page_size'] = $pageSize;
}
echo json_encode($response);
