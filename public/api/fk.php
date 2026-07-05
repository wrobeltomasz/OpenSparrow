<?php

// api/fk.php — Foreign-key options lookup for FK dropdowns (AJAX-only, read-only)
// Auth gate: session + X-Requested-With header required; UA enforcement
// GET table+col -> resolves reference_table from schema.json foreign_keys, returns selectable rows
// Defensive: returns {"rows":[]} on any failure (missing schema, unknown relation)

require_once __DIR__ . '/../../includes/bootstrap.php';

// Read-only AJAX endpoint: auth + AJAX gates, no CSRF, no DB connection here
// (the request is delegated to api.php below, which connects itself)
os_api_bootstrap(['connect' => false, 'require_ajax' => true, 'csrf' => 'none']);

$table = $_GET['table'] ?? '';
$col = $_GET['col'] ?? '';
$schemaPath = __DIR__ . '/../../config/schema.json';
// Check if schema file exists
if (!file_exists($schemaPath)) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

// Read schema file securely
$raw = file_get_contents($schemaPath);
if ($raw === false) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

$schemaData = json_decode($raw, true);
// Verify valid schema structure and check if relation exists
if (!is_array($schemaData) || !isset($schemaData['tables'][$table]['foreign_keys'][$col])) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

$refTable = $schemaData['tables'][$table]['foreign_keys'][$col]['reference_table'] ?? '';
if (empty($refTable)) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => []]);
    exit;
}

// Validate optional filter_col against reference table columns to prevent injection
$filterCol = $_GET['filter_col'] ?? '';
$filterVal = $_GET['filter_val'] ?? '';
if ($filterCol !== '') {
    $refColumns = array_keys($schemaData['tables'][$refTable]['columns'] ?? []);
    if (!in_array($filterCol, $refColumns, true)) {
        unset($_GET['filter_col'], $_GET['filter_val']);
    }
}

// Rewrite GET parameters to simulate a direct call to api.php for the reference table
$_GET['api'] = 'list';
$_GET['table'] = $refTable;
// Delegate response generation to main API handler
require __DIR__ . '/../api.php';
exit;
