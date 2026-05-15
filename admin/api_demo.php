<?php

declare(strict_types=1);

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    exit;
}

/* ── Demo: status ────────────────────────────────────────────────── */
if ($action === 'demo_status') {
    header('Content-Type: application/json');
    $metaPath = realpath(__DIR__ . '/../includes') . '/demo_meta.json';
    if (file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        echo json_encode(['status' => 'success', 'installed' => true, 'meta' => $meta]);
    } else {
        echo json_encode(['status' => 'success', 'installed' => false]);
    }
    exit;
}

/* ── Demo: install ───────────────────────────────────────────────── */
if ($action === 'demo_install') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $type    = $body['type']    ?? '';
    $confirm = $body['confirm'] ?? '';

    if (!in_array($type, ['crm', 'wms', 'tasks'], true)) {
        echo json_encode(['status' => 'error', 'error' => 'Invalid demo type.']);
        exit;
    }
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn     = db_connect();
        $demoData = demo_get_definition($type, $conn);

        // Run DDL
        foreach ($demoData['ddl'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:ddl:{$type}");
            }
        }

        // Seed data
        foreach ($demoData['seed_data'] as $sql) {
            $res = @pg_query($conn, $sql);
            if ($res === false) {
                admin_db_fail($conn, "demo_install:seed:{$type}");
            }
        }

        $includes = realpath(__DIR__ . '/../includes');

        // schema.json
        $schemaPath = $includes . '/schema.json';
        $schemaCfg  = file_exists($schemaPath) ? (json_decode(file_get_contents($schemaPath), true) ?? []) : [];
        if (!isset($schemaCfg['tables']) || !is_array($schemaCfg['tables'])) {
            $schemaCfg['tables'] = [];
        }
        foreach ($demoData['schema_tables'] as $key => $def) {
            $schemaCfg['tables'][$key] = $def;
        }
        file_put_contents($schemaPath, json_encode($schemaCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // dashboard.json
        $dashPath = $includes . '/dashboard.json';
        $dashCfg  = file_exists($dashPath) ? (json_decode(file_get_contents($dashPath), true) ?? []) : [];
        if (!isset($dashCfg['widgets']) || !is_array($dashCfg['widgets'])) {
            $dashCfg['widgets'] = [];
        }
        if (!isset($dashCfg['layout'])) {
            $dashCfg['layout'] = ['gap' => '20px'];
        }
        foreach ($demoData['dashboard_widgets'] as $w) {
            $wid = $w['id'];
            $dashCfg['widgets'] = array_values(
                array_filter($dashCfg['widgets'], fn($x) => ($x['id'] ?? '') !== $wid)
            );
            $dashCfg['widgets'][] = $w;
        }
        // Rebuild in correct order: layout, widgets, menu fields
        $dashCfgOrdered = [
            'layout' => $dashCfg['layout'],
            'widgets' => $dashCfg['widgets'],
        ];
        if (isset($dashCfg['menu_name'])) $dashCfgOrdered['menu_name'] = $dashCfg['menu_name'];
        if (isset($dashCfg['menu_icon'])) $dashCfgOrdered['menu_icon'] = $dashCfg['menu_icon'];
        if (isset($dashCfg['hidden'])) $dashCfgOrdered['hidden'] = $dashCfg['hidden'];
        file_put_contents($dashPath, json_encode($dashCfgOrdered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // calendar.json
        $calPath  = $includes . '/calendar.json';
        $calCfg   = file_exists($calPath) ? (json_decode(file_get_contents($calPath), true) ?? []) : [];
        if (!isset($calCfg['sources']) || !is_array($calCfg['sources'])) {
            $calCfg['sources'] = [];
        }
        $demoTbls = array_keys($demoData['schema_tables']);
        $calCfg['sources'] = array_values(
            array_filter($calCfg['sources'], fn($s) => !in_array($s['table'] ?? '', $demoTbls, true))
        );
        foreach ($demoData['calendar_sources'] as $s) {
            $calCfg['sources'][] = $s;
        }
        file_put_contents($calPath, json_encode($calCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // workflows.json
        $wfPath = $includes . '/workflows.json';
        $wfCfg  = file_exists($wfPath) ? (json_decode(file_get_contents($wfPath), true) ?? []) : [];
        if (!isset($wfCfg['workflows']) || !is_array($wfCfg['workflows'])) {
            $wfCfg['workflows'] = [];
        }
        foreach ($demoData['workflows'] as $wf) {
            $wid = $wf['id'];
            $wfCfg['workflows'] = array_values(
                array_filter($wfCfg['workflows'], fn($w) => ($w['id'] ?? '') !== $wid)
            );
            $wfCfg['workflows'][] = $wf;
        }
        // Preserve/add menu fields
        if (!isset($wfCfg['menu_name'])) $wfCfg['menu_name'] = 'Workflows';
        if (!isset($wfCfg['menu_icon'])) $wfCfg['menu_icon'] = 'assets/icons/automation.png';
        // Rebuild in correct order: workflows, menu_name, menu_icon
        $wfCfgOrdered = ['workflows' => $wfCfg['workflows'], 'menu_name' => $wfCfg['menu_name'], 'menu_icon' => $wfCfg['menu_icon']];
        file_put_contents($wfPath, json_encode($wfCfgOrdered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // views.json
        $viewsPath = $includes . '/views.json';
        $viewsCfg  = file_exists($viewsPath) ? (json_decode(file_get_contents($viewsPath), true) ?? []) : [];
        if (!isset($viewsCfg['views']) || !is_array($viewsCfg['views'])) {
            $viewsCfg['views'] = [];
        }
        foreach ($demoData['views'] as $key => $def) {
            $viewsCfg['views'][$key] = $def;
        }
        file_put_contents($viewsPath, json_encode($viewsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // demo_meta.json
        $meta = [
            'type'         => $type,
            'schema'       => $demoData['pg_schema'],
            'installed_at' => date('Y-m-d H:i:s'),
            'tables'       => array_keys($demoData['schema_tables']),
            'widget_ids'   => array_column($demoData['dashboard_widgets'], 'id'),
            'workflow_ids' => array_column($demoData['workflows'], 'id'),
            'view_keys'    => array_keys($demoData['views']),
            'view_names'   => $demoData['view_names'],
        ];
        file_put_contents(
            $includes . '/demo_meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_INSTALL', 'demo', null);
        echo json_encode(['status' => 'success', 'meta' => $meta]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Demo: uninstall ─────────────────────────────────────────────── */
if ($action === 'demo_uninstall') {
    header('Content-Type: application/json');
    if ($isDemoMode) {
        echo json_encode(['status' => 'error', 'error' => 'Demo mode — writes disabled.']);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $confirm = $body['confirm'] ?? '';
    if ($confirm !== 'CONFIRM') {
        echo json_encode(['status' => 'error', 'error' => 'Confirmation required.']);
        exit;
    }

    $includes = realpath(__DIR__ . '/../includes');
    $metaPath = $includes . '/demo_meta.json';
    if (!file_exists($metaPath)) {
        echo json_encode(['status' => 'error', 'error' => 'No demo installed.']);
        exit;
    }

    $meta = json_decode(file_get_contents($metaPath), true) ?? [];

    try {
        require_once __DIR__ . '/../includes/db.php';
        $conn = db_connect();

        // Drop demo schema + all objects
        $pgSchema = $meta['schema'] ?? '';
        if ($pgSchema && preg_match('/^spw_(crm|wms|tasks)$/', $pgSchema)) {
            @pg_query($conn, 'DROP SCHEMA IF EXISTS ' . pg_ident($pgSchema) . ' CASCADE');
        }

        // Drop views from app schema
        $appSchema = sys_schema();
        foreach ($meta['view_names'] ?? [] as $vName) {
            if (preg_match('/^v_demo_[a-z_]+$/', $vName)) {
                @pg_query($conn, 'DROP VIEW IF EXISTS ' . pg_ident($appSchema) . '.' . pg_ident($vName));
            }
        }

        // Clean schema.json (delete if empty)
        $schemaPath = $includes . '/schema.json';
        if (file_exists($schemaPath)) {
            $cfg = json_decode(file_get_contents($schemaPath), true) ?? [];
            foreach ($meta['tables'] ?? [] as $t) {
                unset($cfg['tables'][$t]);
            }
            if (empty($cfg['tables'])) {
                @unlink($schemaPath);
            } else {
                file_put_contents($schemaPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean dashboard.json (delete if empty)
        $dashPath = $includes . '/dashboard.json';
        if (file_exists($dashPath)) {
            $cfg = json_decode(file_get_contents($dashPath), true) ?? [];
            $ids = $meta['widget_ids'] ?? [];
            $cfg['widgets'] = array_values(
                array_filter($cfg['widgets'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($cfg['widgets'])) {
                @unlink($dashPath);
            } else {
                file_put_contents($dashPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean calendar.json (delete if empty)
        $calPath = $includes . '/calendar.json';
        if (file_exists($calPath)) {
            $cfg  = json_decode(file_get_contents($calPath), true) ?? [];
            $tbls = $meta['tables'] ?? [];
            $cfg['sources'] = array_values(
                array_filter($cfg['sources'] ?? [], fn($s) => !in_array($s['table'] ?? '', $tbls, true))
            );
            if (empty($cfg['sources'])) {
                @unlink($calPath);
            } else {
                file_put_contents($calPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean workflows.json (delete if empty)
        $wfPath = $includes . '/workflows.json';
        if (file_exists($wfPath)) {
            $cfg = json_decode(file_get_contents($wfPath), true) ?? [];
            $ids = $meta['workflow_ids'] ?? [];
            $cfg['workflows'] = array_values(
                array_filter($cfg['workflows'] ?? [], fn($w) => !in_array($w['id'] ?? '', $ids, true))
            );
            if (empty($cfg['workflows'])) {
                @unlink($wfPath);
            } else {
                file_put_contents($wfPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean views.json (delete if empty)
        $viewsPath = $includes . '/views.json';
        if (file_exists($viewsPath)) {
            $cfg = json_decode(file_get_contents($viewsPath), true) ?? [];
            foreach ($meta['view_keys'] ?? [] as $k) {
                unset($cfg['views'][$k]);
            }
            if (empty($cfg['views'])) {
                @unlink($viewsPath);
            } else {
                file_put_contents($viewsPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        @unlink($metaPath);
        log_user_action($conn, (int)($_SESSION['user_id'] ?? 0), 'DEMO_UNINSTALL', 'demo', null);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Demo: definition helper ─────────────────────────────────────── */
function demo_get_definition(string $type, $conn): array
{
    $appSchema = sys_schema();

    switch ($type) {
        case 'crm':
            return [
                'pg_schema'  => 'spw_crm',
                'view_names' => ['v_demo_crm_pipeline'],
                'ddl' => [
                    'CREATE SCHEMA IF NOT EXISTS spw_crm',
                    "CREATE TABLE IF NOT EXISTS spw_crm.companies (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, industry VARCHAR(100), website VARCHAR(255), phone VARCHAR(50), email VARCHAR(255), created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.contacts (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255), phone VARCHAR(50), position VARCHAR(100), created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.deals (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, title VARCHAR(255) NOT NULL, value NUMERIC(12,2), stage VARCHAR(50) DEFAULT 'Lead', expected_close DATE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.activities (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE CASCADE, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, type VARCHAR(50) DEFAULT 'Call', notes TEXT, scheduled_at TIMESTAMP, done BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT NOW())",
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_crm_pipeline AS SELECT stage, COUNT(*) AS deal_count, COALESCE(SUM(value), 0) AS total_value FROM spw_crm.deals GROUP BY stage ORDER BY stage',
                ],
                'seed_data' => [
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Acme Corporation', 'Technology', 'acme.com', '+1-555-1001', 'sales@acme.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Global Solutions Ltd', 'Consulting', 'globalsol.com', '+1-555-1002', 'info@globalsol.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('TechVision Inc', 'Software', 'techvision.io', '+1-555-1003', 'contact@techvision.io')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Enterprise Systems', 'IT Services', 'entsys.net', '+1-555-1004', 'support@entsys.net')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Digital Innovators Co', 'Digital Agency', 'diginnovate.com', '+1-555-1005', 'hello@diginnovate.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CloudFirst Partners', 'Cloud Services', 'cloudfirst.io', '+1-555-1006', 'team@cloudfirst.io')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('DataStream Analytics', 'Analytics', 'datastream.io', '+1-555-1007', 'contact@datastream.io')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('SecureNet Technologies', 'Cybersecurity', 'securenet.com', '+1-555-1008', 'sales@securenet.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('InnovateLabs', 'R&D', 'innovatelabs.io', '+1-555-1009', 'hello@innovatelabs.io')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('BrightBridge Solutions', 'Management Consulting', 'brightbridge.com', '+1-555-1010', 'info@brightbridge.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('NextGen Dynamics', 'Business Services', 'nextgendyn.com', '+1-555-1011', 'contact@nextgendyn.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Vertex Solutions', 'Enterprise Software', 'vertexsol.com', '+1-555-1012', 'sales@vertexsol.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Momentum Partners', 'Private Equity', 'momentum.io', '+1-555-1013', 'hello@momentum.io')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('PureScale Marketing', 'Marketing Services', 'purescale.com', '+1-555-1014', 'team@purescale.com')",
                    "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('QuantumLeap Ventures', 'Venture Capital', 'quantumleap.io', '+1-555-1015', 'invest@quantumleap.io')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (1, 'John', 'Smith', 'john.smith@acme.com', '+1-555-2001', 'Sales Director')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (1, 'Sarah', 'Johnson', 'sarah.j@acme.com', '+1-555-2002', 'Product Manager')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (2, 'Michael', 'Brown', 'mbrown@globalsol.com', '+1-555-2003', 'Chief Strategy Officer')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (3, 'Emma', 'Wilson', 'emma.w@techvision.io', '+1-555-2004', 'Head of Sales')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (4, 'David', 'Miller', 'david.m@entsys.net', '+1-555-2005', 'IT Director')",
                    "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (5, 'Lisa', 'Garcia', 'lisa.g@diginnovate.com', '+1-555-2006', 'Creative Director')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (1, 1, 'Enterprise License Q2', 45000.00, 'Proposal', '2026-06-30')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (2, 3, 'Digital Transformation Project', 120000.00, 'Negotiation', '2026-07-15')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (3, 4, 'Cloud Migration Services', 85000.00, 'Qualified', '2026-06-01')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (4, 5, 'Support & Maintenance', 35000.00, 'Won', '2026-05-20')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (5, 6, 'Marketing Campaign Development', 55000.00, 'Lead', '2026-08-01')",
                    "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (1, 2, 'Integration Consulting', 25000.00, 'Proposal', '2026-07-01')",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (1, 1, 'Call', 'Discussed budget and timeline', NOW() - INTERVAL '2 days', true)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (2, 3, 'Meeting', 'Presentation to stakeholders', NOW() + INTERVAL '3 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (3, 4, 'Email', 'Sent proposal document', NOW() - INTERVAL '5 days', true)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (4, 5, 'Task', 'Follow-up on implementation', NOW() + INTERVAL '4 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (5, 6, 'Note', 'Initial contact qualifies as lead', NOW() - INTERVAL '1 day', true)",
                ],
                'schema_tables' => [
                    'companies' => ['display_name' => 'Companies', 'schema' => 'spw_crm', 'icon' => 'assets/icons/apartment.png', 'columns' => [
                        'id'         => ['type' => 'number', 'show_in_grid' => false, 'description' => 'Unique company identifier'],
                        'name'       => ['type' => 'text',   'show_in_grid' => true,  'display_name' => 'Company Name', 'not_null' => true, 'description' => 'Official company name'],
                        'industry'   => ['type' => 'text',   'show_in_grid' => true, 'description' => 'Industry or sector the company operates in'],
                        'website'    => ['type' => 'text',   'show_in_grid' => true, 'description' => 'Company website URL'],
                        'phone'      => ['type' => 'text',   'show_in_grid' => true, 'description' => 'Main company phone number'],
                        'email'      => ['type' => 'text',   'show_in_grid' => true, 'description' => 'Company email address'],
                        'created_at' => ['type' => 'date',   'show_in_grid' => true,  'readonly' => true, 'description' => 'Date when company record was created'],
                    ], 'subtables' => [
                        ['table' => 'contacts', 'foreign_key' => 'company_id', 'label' => 'Contacts', 'columns_to_show' => ['first_name', 'last_name', 'email', 'position']],
                        ['table' => 'deals',    'foreign_key' => 'company_id', 'label' => 'Deals',    'columns_to_show' => ['title', 'stage', 'value', 'expected_close']],
                    ]],
                    'contacts' => ['display_name' => 'Contacts', 'schema' => 'spw_crm', 'icon' => 'assets/icons/person.png', 'columns' => [
                        'id'         => ['type' => 'number', 'description' => 'Unique contact identifier'],
                        'company_id' => ['type' => 'number', 'description' => 'Company this contact belongs to'],
                        'first_name' => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'First Name', 'not_null' => true, 'description' => 'Contact first name'],
                        'last_name'  => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Last Name',  'not_null' => true, 'description' => 'Contact last name'],
                        'email'      => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Contact email address'],
                        'phone'      => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Contact phone number'],
                        'position'   => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Job title or position at company'],
                        'created_at' => ['type' => 'date', 'readonly' => true, 'description' => 'Date when contact record was created'],
                    ], 'foreign_keys' => [
                        'company_id' => ['reference_table' => 'companies', 'reference_column' => 'id', 'display_column' => 'name'],
                    ], 'subtables' => [
                        ['table' => 'activities', 'foreign_key' => 'contact_id', 'label' => 'Activities', 'columns_to_show' => ['type', 'scheduled_at', 'done']],
                    ]],
                    'deals' => ['display_name' => 'Deals', 'schema' => 'spw_crm', 'icon' => 'assets/icons/point_of_sale.png', 'columns' => [
                        'id'             => ['type' => 'number', 'description' => 'Unique deal identifier'],
                        'company_id'     => ['type' => 'number', 'description' => 'Company associated with this deal'],
                        'contact_id'     => ['type' => 'number', 'description' => 'Primary contact for this deal'],
                        'title'          => ['type' => 'text',   'show_in_grid' => true, 'not_null' => true, 'description' => 'Deal name or description'],
                        'value'          => ['type' => 'number', 'show_in_grid' => true, 'description' => 'Estimated deal value in currency units'],
                        'stage'          => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Lead', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'], 'enum_colors' => ['Lead' => '#d4d4d4', 'Qualified' => '#a8deff', 'Proposal' => '#ffe875', 'Negotiation' => '#ffc107', 'Won' => '#75ff91', 'Lost' => '#ff9185'], 'description' => 'Current stage in sales pipeline'],
                        'expected_close' => ['type' => 'date',   'show_in_grid' => true, 'description' => 'Projected closing date'],
                        'created_at'     => ['type' => 'date',   'readonly' => true, 'description' => 'Date when deal record was created'],
                    ], 'foreign_keys' => [
                        'company_id' => ['reference_table' => 'companies', 'reference_column' => 'id', 'display_column' => 'name'],
                        'contact_id' => ['reference_table' => 'contacts',  'reference_column' => 'id', 'display_column' => 'first_name'],
                    ], 'subtables' => [
                        ['table' => 'activities', 'foreign_key' => 'deal_id', 'label' => 'Activities', 'columns_to_show' => ['type', 'scheduled_at', 'done', 'notes']],
                    ]],
                    'activities' => ['display_name' => 'Activities', 'schema' => 'spw_crm', 'icon' => 'assets/icons/calendar.png', 'columns' => [
                        'id'           => ['type' => 'number', 'description' => 'Unique activity identifier'],
                        'deal_id'      => ['type' => 'number', 'description' => 'Deal this activity is associated with'],
                        'contact_id'   => ['type' => 'number', 'description' => 'Contact involved in this activity'],
                        'type'         => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Call', 'Email', 'Meeting', 'Task', 'Note'], 'enum_colors' => ['Call' => '#3b82f6', 'Email' => '#10b981', 'Meeting' => '#f59e0b', 'Task' => '#8b5cf6', 'Note' => '#6b7280'], 'description' => 'Type of activity performed'],
                        'notes'        => ['type' => 'text',    'show_in_grid' => false, 'description' => 'Detailed notes or comments about the activity'],
                        'scheduled_at' => ['type' => 'date',    'show_in_grid' => true, 'description' => 'Date and time activity is scheduled or occurred'],
                        'done'         => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#10b981', 'false' => '#ef4444'], 'description' => 'Whether activity is completed'],
                        'created_at'   => ['type' => 'date',    'readonly' => true, 'description' => 'Date when activity record was created'],
                    ], 'foreign_keys' => [
                        'deal_id'    => ['reference_table' => 'deals',    'reference_column' => 'id', 'display_column' => 'title'],
                        'contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => 'last_name'],
                    ]],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_crm_001', 'type' => 'stat_card', 'title' => 'Companies', 'table' => 'companies', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/apartment.png', 'color' => '#3b82f6', 'display_columns' => []],
                    ['id' => 'demo_crm_002', 'type' => 'stat_card', 'title' => 'Contacts', 'table' => 'contacts', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/person.png', 'color' => '#10b981', 'display_columns' => []],
                    ['id' => 'demo_crm_004', 'type' => 'stat_card', 'title' => 'Pipeline Value', 'table' => 'deals', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/payments.png', 'color' => '#ef4444', 'display_columns' => []],
                    ['id' => 'demo_crm_003', 'type' => 'bar_chart', 'title' => 'Deals by Stage', 'table' => 'deals', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'stage'], 'icon' => 'assets/icons/point_of_sale.png', 'color' => '#f59e0b', 'display_columns' => []],
                    ['id' => 'demo_crm_005', 'type' => 'pie_chart', 'title' => 'Activities Status', 'table' => 'activities', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'done'], 'icon' => 'assets/icons/calendar.png', 'color' => '#8b5cf6', 'display_columns' => []],
                ],
                'calendar_sources' => [
                    ['table' => 'activities', 'date_column' => 'scheduled_at', 'title_column' => 'type', 'color' => '#3b82f6', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=activities&id={id}', 'icon' => 'assets/icons/calendar.png', 'notified_users' => []],
                    ['table' => 'deals', 'date_column' => 'expected_close', 'title_column' => 'title', 'color' => '#f59e0b', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=deals&id={id}', 'icon' => 'assets/icons/point_of_sale.png', 'notified_users' => []],
                ],
                'workflows' => [
                    ['id' => 'wf_demo_crm_001', 'title' => 'New CRM Deal', 'icon' => 'assets/icons/apartment.png', 'description' => 'CRM: add company → contact → deal → activity.', 'steps' => [
                        ['title' => 'Add Company',  'table' => 'companies',  'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Add Contact',  'table' => 'contacts',   'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => true],
                        ['title' => 'Create Deal',  'table' => 'deals',      'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Log Activity', 'table' => 'activities', 'foreign_key' => 'deal_id',    'link_to_step' => 2, 'allow_multiple' => true],
                    ]],
                ],
                'views' => [
                    'v_demo_crm_pipeline' => ['display_name' => 'CRM Pipeline', 'menu_name' => 'Pipeline Summary', 'icon' => 'assets/icons/point_of_sale.png', 'hidden' => false, 'description' => 'Deal count & value by stage.', 'columns' => [
                        'stage'       => ['display_name' => 'Stage'],
                        'deal_count'  => ['display_name' => 'Deals'],
                        'total_value' => ['display_name' => 'Total Value'],
                    ], 'drill_down' => ['enabled' => false]],
                ],
            ];

        case 'wms':
            return [
                'pg_schema'  => 'spw_wms',
                'view_names' => ['v_demo_wms_stock', 'v_demo_wms_low_stock'],
                'ddl' => [
                    'CREATE SCHEMA IF NOT EXISTS spw_wms',
                    "CREATE TABLE IF NOT EXISTS spw_wms.warehouses (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, location VARCHAR(255), capacity INTEGER, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_wms.products (id SERIAL PRIMARY KEY, sku VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, description TEXT, unit VARCHAR(50), category VARCHAR(100), created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_wms.stock (id SERIAL PRIMARY KEY, warehouse_id INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE CASCADE, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE CASCADE, quantity INTEGER DEFAULT 0, min_quantity INTEGER DEFAULT 0, updated_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_wms.movements (id SERIAL PRIMARY KEY, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE SET NULL, warehouse_from INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, warehouse_to INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, quantity INTEGER NOT NULL, type VARCHAR(50) DEFAULT 'Transfer', notes TEXT, moved_at TIMESTAMP DEFAULT NOW())",
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_wms_stock AS SELECT p.sku, p.name AS product, p.category, w.name AS warehouse, s.quantity, s.min_quantity, (s.quantity < s.min_quantity) AS low_stock FROM spw_wms.stock s JOIN spw_wms.products p ON p.id = s.product_id JOIN spw_wms.warehouses w ON w.id = s.warehouse_id',
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_wms_low_stock AS SELECT s.id, p.sku, p.name AS product, w.name AS warehouse, s.quantity, s.min_quantity FROM spw_wms.stock s JOIN spw_wms.products p ON p.id = s.product_id JOIN spw_wms.warehouses w ON w.id = s.warehouse_id WHERE s.quantity < s.min_quantity ORDER BY s.quantity ASC',
                ],
                'seed_data' => [
                    "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('Central Hub', 'Chicago, USA', 50000)",
                    "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('West Coast DC', 'Los Angeles, USA', 35000)",
                    "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('East Coast Distribution', 'New York, USA', 45000)",
                    "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('European Facility', 'Amsterdam, Netherlands', 30000)",
                    "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('Asia Pacific', 'Singapore', 40000)",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-001', 'Wireless Mouse', 'Ergonomic 2.4GHz wireless mouse', 'Unit', 'Electronics')",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-002', 'USB-C Cable', '2-meter high-speed USB-C cable', 'Unit', 'Accessories')",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-003', 'Laptop Stand', 'Adjustable aluminum laptop stand', 'Unit', 'Office')",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-004', 'Keyboard', 'Mechanical RGB gaming keyboard', 'Unit', 'Electronics')",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-005', 'Monitor', '27-inch 4K UHD monitor', 'Unit', 'Electronics')",
                    "INSERT INTO spw_wms.products (sku, name, description, unit, category) VALUES ('PROD-006', 'Desk Lamp', 'LED desk lamp with USB charging', 'Unit', 'Office')",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (1, 1, 450, 100)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (1, 2, 1200, 200)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (1, 3, 85, 50)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (2, 1, 320, 100)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (2, 4, 40, 80)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (3, 5, 55, 40)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (3, 6, 200, 100)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (4, 2, 890, 200)",
                    "INSERT INTO spw_wms.stock (warehouse_id, product_id, quantity, min_quantity) VALUES (5, 1, 520, 100)",
                    "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (1, 1, 2, 100, 'Transfer', 'Regular stock replenishment', NOW() - INTERVAL '3 days')",
                    "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (2, 1, 3, 300, 'Transfer', 'Support West region demand', NOW() - INTERVAL '1 day')",
                    "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (3, 5, 1, 200, 'Inbound', 'Received from supplier', NOW() + INTERVAL '2 days')",
                    "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (4, 2, 5, 150, 'Outbound', 'Shipped to customer ABC', NOW() - INTERVAL '4 days')",
                    "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (5, 3, 4, 20, 'Transfer', 'Inventory adjustment', NOW() + INTERVAL '1 day')",
                ],
                'schema_tables' => [
                    'warehouses' => ['display_name' => 'Warehouses', 'schema' => 'spw_wms', 'icon' => 'assets/icons/warehouse.png', 'columns' => [
                        'id'         => ['type' => 'number', 'description' => 'Unique warehouse identifier'],
                        'name'       => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Warehouse name or facility designation'],
                        'location'   => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Geographic location of warehouse (city, country)'],
                        'capacity'   => ['type' => 'number', 'show_in_grid' => true, 'description' => 'Maximum storage capacity in units'],
                        'created_at' => ['type' => 'date', 'readonly' => true, 'description' => 'Date when warehouse record was created'],
                    ], 'subtables' => [
                        ['table' => 'stock', 'foreign_key' => 'warehouse_id', 'label' => 'Stock', 'columns_to_show' => ['product_id', 'quantity', 'min_quantity']],
                    ]],
                    'products' => ['display_name' => 'Products', 'schema' => 'spw_wms', 'icon' => 'assets/icons/package_2.png', 'columns' => [
                        'id'          => ['type' => 'number', 'description' => 'Unique product identifier'],
                        'sku'         => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Stock Keeping Unit - unique product code'],
                        'name'        => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Product name'],
                        'description' => ['type' => 'text', 'description' => 'Detailed product description and specifications'],
                        'unit'        => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Unit of measurement (pieces, kg, liters, etc.)'],
                        'category'    => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Product category or classification'],
                        'created_at'  => ['type' => 'date', 'readonly' => true, 'description' => 'Date when product record was created'],
                    ], 'subtables' => [
                        ['table' => 'stock',     'foreign_key' => 'product_id', 'label' => 'Stock',     'columns_to_show' => ['warehouse_id', 'quantity', 'min_quantity']],
                        ['table' => 'movements', 'foreign_key' => 'product_id', 'label' => 'Movements', 'columns_to_show' => ['warehouse_from', 'warehouse_to', 'quantity', 'type']],
                    ]],
                    'stock' => ['display_name' => 'Stock', 'schema' => 'spw_wms', 'icon' => 'assets/icons/inventory.png', 'columns' => [
                        'id'           => ['type' => 'number', 'description' => 'Unique stock record identifier'],
                        'warehouse_id' => ['type' => 'number', 'description' => 'Warehouse where stock is stored'],
                        'product_id'   => ['type' => 'number', 'description' => 'Product in this stock record'],
                        'quantity'     => ['type' => 'number', 'show_in_grid' => true, 'description' => 'Current quantity in stock'],
                        'min_quantity' => ['type' => 'number', 'show_in_grid' => true, 'description' => 'Minimum threshold quantity'],
                        'updated_at'   => ['type' => 'date', 'readonly' => true, 'description' => 'Date when stock quantity was last updated'],
                    ], 'foreign_keys' => [
                        'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                        'product_id'   => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                    ]],
                    'movements' => ['display_name' => 'Movements', 'schema' => 'spw_wms', 'icon' => 'assets/icons/arrow_split.png', 'columns' => [
                        'id'             => ['type' => 'number', 'description' => 'Unique movement record identifier'],
                        'product_id'     => ['type' => 'number', 'description' => 'Product being moved'],
                        'warehouse_from' => ['type' => 'number', 'description' => 'Source warehouse (null for inbound)'],
                        'warehouse_to'   => ['type' => 'number', 'description' => 'Destination warehouse (null for outbound)'],
                        'quantity'       => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Quantity moved'],
                        'type'           => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Inbound', 'Outbound', 'Transfer', 'Adjustment'], 'enum_colors' => ['Inbound' => '#10b981', 'Outbound' => '#ef4444', 'Transfer' => '#f59e0b', 'Adjustment' => '#8b5cf6'], 'description' => 'Movement type'],
                        'notes'          => ['type' => 'text', 'description' => 'Additional movement notes'],
                        'moved_at'       => ['type' => 'date', 'readonly' => true, 'description' => 'Date when movement was recorded'],
                    ], 'foreign_keys' => [
                        'product_id'     => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                        'warehouse_from' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                        'warehouse_to'   => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                    ]],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_wms_001', 'type' => 'stat_card', 'title' => 'Warehouses', 'table' => 'warehouses', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/warehouse.png', 'color' => '#f59e0b', 'display_columns' => []],
                    ['id' => 'demo_wms_002', 'type' => 'stat_card', 'title' => 'Products', 'table' => 'products', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/package_2.png', 'color' => '#3b82f6', 'display_columns' => []],
                    ['id' => 'demo_wms_005', 'type' => 'stat_card', 'title' => 'Stock Entries', 'table' => 'stock', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/manage_history.png', 'color' => '#10b981', 'display_columns' => []],
                    ['id' => 'demo_wms_004', 'type' => 'bar_chart', 'title' => 'Movements by Type', 'table' => 'movements', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'type'], 'icon' => 'assets/icons/arrow_split.png', 'color' => '#8b5cf6', 'display_columns' => []],
                    ['id' => 'demo_wms_006', 'type' => 'pie_chart', 'title' => 'Stock by Category', 'table' => 'products', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'category'], 'icon' => 'assets/icons/package_2.png', 'color' => '#3b82f6', 'display_columns' => []],
                ],
                'calendar_sources' => [
                    ['table' => 'movements', 'date_column' => 'moved_at', 'title_column' => 'type', 'color' => '#f59e0b', 'notify_before_days' => 0, 'url_template' => 'edit.php?table=movements&id={id}', 'icon' => 'assets/icons/arrow_split.png', 'notified_users' => []],
                ],
                'workflows' => [
                    ['id' => 'wf_demo_wms_001', 'title' => 'New Stock Movement', 'icon' => 'assets/icons/warehouse.png', 'description' => 'WMS: add product → set stock → log movement.', 'steps' => [
                        ['title' => 'Add Product', 'table' => 'products',  'foreign_key' => '', 'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Set Stock',  'table' => 'stock',     'foreign_key' => 'product_id', 'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Log Move',   'table' => 'movements', 'foreign_key' => 'product_id', 'link_to_step' => 0, 'allow_multiple' => true],
                    ]],
                ],
                'views' => [
                    'v_demo_wms_stock' => ['display_name' => 'WMS Stock Overview', 'menu_name' => 'Stock', 'icon' => 'assets/icons/warehouse.png', 'hidden' => false, 'description' => 'Stock levels by product & warehouse.', 'columns' => [
                        'sku'          => ['display_name' => 'SKU'],
                        'product'      => ['display_name' => 'Product'],
                        'category'     => ['display_name' => 'Category'],
                        'warehouse'    => ['display_name' => 'Warehouse'],
                        'quantity'     => ['display_name' => 'Qty'],
                        'min_quantity' => ['display_name' => 'Min'],
                        'low_stock'    => ['display_name' => 'Low', 'color_rules' => [['op' => '=', 'value' => 'true', 'color' => '#ef4444']]],
                    ], 'drill_down' => ['enabled' => false]],
                ],
            ];

        case 'tasks':
            return [
                'pg_schema'  => 'spw_tasks',
                'view_names' => ['v_demo_tasks_summary'],
                'ddl' => [
                    'CREATE SCHEMA IF NOT EXISTS spw_tasks',
                    "CREATE TABLE IF NOT EXISTS spw_tasks.projects (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, status VARCHAR(50) DEFAULT 'Active', priority VARCHAR(50) DEFAULT 'Medium', due_date DATE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_tasks.tasks (id SERIAL PRIMARY KEY, project_id INTEGER REFERENCES spw_tasks.projects(id) ON DELETE CASCADE, title VARCHAR(255) NOT NULL, description TEXT, status VARCHAR(50) DEFAULT 'Todo', priority VARCHAR(50) DEFAULT 'Medium', assigned_to VARCHAR(100), due_date DATE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_tasks.time_logs (id SERIAL PRIMARY KEY, task_id INTEGER REFERENCES spw_tasks.tasks(id) ON DELETE CASCADE, hours NUMERIC(5,2) NOT NULL, description VARCHAR(255), logged_at TIMESTAMP DEFAULT NOW())",
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_tasks_summary AS SELECT p.name AS project, t.status, COUNT(*) AS task_count FROM spw_tasks.tasks t JOIN spw_tasks.projects p ON p.id = t.project_id GROUP BY p.name, t.status ORDER BY p.name, t.status',
                ],
                'seed_data' => [
                    "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Website Redesign', 'Complete overhaul of corporate website', 'Active', 'High', NOW() + INTERVAL '75 days')",
                    "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Mobile App Launch', 'Native iOS and Android applications', 'Active', 'Critical', NOW() + INTERVAL '32 days')",
                    "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Cloud Migration', 'Move infrastructure to AWS', 'On Hold', 'High', NOW() + INTERVAL '108 days')",
                    "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('API Documentation', 'Comprehensive REST API documentation', 'Active', 'Medium', NOW() + INTERVAL '47 days')",
                    "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Security Audit', 'Third-party security assessment', 'Completed', 'Critical', NOW() - INTERVAL '14 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (1, 'Design mockups', 'Create Figma designs for homepage', 'In Progress', 'High', 'Alice', NOW() + INTERVAL '18 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (1, 'Frontend development', 'Implement React components', 'Todo', 'High', 'Bob', NOW() + INTERVAL '32 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (2, 'iOS app development', 'Build native iOS app', 'In Progress', 'Critical', 'Charlie', NOW() + INTERVAL '18 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (2, 'Android app development', 'Build native Android app', 'Review', 'Critical', 'Diana', NOW() + INTERVAL '27 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (3, 'Infrastructure planning', 'Plan AWS architecture', 'Todo', 'High', 'Eve', NOW() + INTERVAL '48 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (4, 'Write API docs', 'Document all endpoints', 'Done', 'Medium', 'Frank', NOW() - INTERVAL '2 days')",
                    "INSERT INTO spw_tasks.tasks (project_id, title, description, status, priority, assigned_to, due_date) VALUES (5, 'Vulnerability fixes', 'Address identified issues', 'Done', 'Critical', 'Grace', NOW() - INTERVAL '15 days')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (1, 8.5, 'Completed home page and nav bar designs', NOW() - INTERVAL '1 day')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (1, 6.0, 'Created responsive design variations', NOW() - INTERVAL '3 days')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (3, 10.5, 'Set up iOS project structure and core modules', NOW() - INTERVAL '2 days')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (3, 8.0, 'Implemented authentication flow', NOW() + INTERVAL '1 day')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (4, 9.0, 'Testing and bug fixes', NOW() - INTERVAL '5 days')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (6, 12.0, 'Complete API documentation', NOW() - INTERVAL '4 days')",
                    "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (7, 15.5, 'Security audit response and fixes', NOW() - INTERVAL '10 days')",
                ],
                'schema_tables' => [
                    'projects' => ['display_name' => 'Projects', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/account_tree.png', 'columns' => [
                        'id'          => ['type' => 'number', 'description' => 'Unique project identifier'],
                        'name'        => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Project name or title'],
                        'description' => ['type' => 'text', 'description' => 'Detailed project description'],
                        'status'      => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Active', 'On Hold', 'Completed', 'Cancelled'], 'enum_colors' => ['Active' => '#10b981', 'On Hold' => '#f59e0b', 'Completed' => '#3b82f6', 'Cancelled' => '#ef4444'], 'description' => 'Current project status'],
                        'priority'    => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#6b7280', 'Medium' => '#f59e0b', 'High' => '#ef4444', 'Critical' => '#8b5cf6'], 'description' => 'Project priority level'],
                        'due_date'    => ['type' => 'date', 'show_in_grid' => true, 'description' => 'Projected project completion date'],
                        'created_at'  => ['type' => 'date', 'readonly' => true, 'description' => 'Date when project record was created'],
                    ], 'subtables' => [
                        ['table' => 'tasks', 'foreign_key' => 'project_id', 'label' => 'Tasks', 'columns_to_show' => ['title', 'status', 'priority', 'assigned_to', 'due_date']],
                    ]],
                    'tasks' => ['display_name' => 'Tasks', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/checklist_rtl.png', 'columns' => [
                        'id'          => ['type' => 'number', 'description' => 'Unique task identifier'],
                        'project_id'  => ['type' => 'number', 'description' => 'Project this task belongs to'],
                        'title'       => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Task title or name'],
                        'description' => ['type' => 'text', 'description' => 'Detailed task description'],
                        'status'      => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Todo', 'In Progress', 'Review', 'Done', 'Blocked'], 'enum_colors' => ['Todo' => '#6b7280', 'In Progress' => '#3b82f6', 'Review' => '#f59e0b', 'Done' => '#10b981', 'Blocked' => '#ef4444'], 'description' => 'Current task status'],
                        'priority'    => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#6b7280', 'Medium' => '#f59e0b', 'High' => '#ef4444', 'Critical' => '#8b5cf6'], 'description' => 'Task priority level'],
                        'assigned_to' => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Team member assigned to task'],
                        'due_date'    => ['type' => 'date', 'show_in_grid' => true, 'description' => 'Task completion deadline'],
                        'created_at'  => ['type' => 'date', 'readonly' => true, 'description' => 'Date when task record was created'],
                    ], 'foreign_keys' => [
                        'project_id' => ['reference_table' => 'projects', 'reference_column' => 'id', 'display_column' => 'name'],
                    ], 'subtables' => [
                        ['table' => 'time_logs', 'foreign_key' => 'task_id', 'label' => 'Time Logs', 'columns_to_show' => ['hours', 'description', 'logged_at']],
                    ]],
                    'time_logs' => ['display_name' => 'Time Logs', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/watch_screentime.png', 'columns' => [
                        'id'          => ['type' => 'number', 'description' => 'Unique time log record identifier'],
                        'task_id'     => ['type' => 'number', 'description' => 'Task this time log is for'],
                        'hours'       => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'description' => 'Hours spent on task'],
                        'description' => ['type' => 'text', 'show_in_grid' => true, 'description' => 'Work description and notes'],
                        'logged_at'   => ['type' => 'date', 'readonly' => true, 'description' => 'Date when time was logged'],
                    ], 'foreign_keys' => [
                        'task_id' => ['reference_table' => 'tasks', 'reference_column' => 'id', 'display_column' => 'title'],
                    ]],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_tasks_001', 'type' => 'stat_card', 'title' => 'Projects', 'table' => 'projects', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/account_tree.png', 'color' => '#10b981', 'display_columns' => []],
                    ['id' => 'demo_tasks_002', 'type' => 'stat_card', 'title' => 'Open Tasks', 'table' => 'tasks', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/checklist_rtl.png', 'color' => '#3b82f6', 'display_columns' => []],
                    ['id' => 'demo_tasks_004', 'type' => 'stat_card', 'title' => 'Total Hours', 'table' => 'time_logs', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/watch_screentime.png', 'color' => '#8b5cf6', 'display_columns' => []],
                    ['id' => 'demo_tasks_003', 'type' => 'pie_chart', 'title' => 'Task Status', 'table' => 'tasks', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'status'], 'icon' => 'assets/icons/checklist_rtl.png', 'color' => '#f59e0b', 'display_columns' => []],
                    ['id' => 'demo_tasks_005', 'type' => 'list', 'title' => 'Overdue Tasks', 'table' => 'tasks', 'width' => 3, 'height' => 2, 'query' => [], 'icon' => 'assets/icons/fact_check.png', 'color' => '#ef4444', 'display_columns' => ['title', 'project_id', 'assigned_to', 'due_date']],
                ],
                'calendar_sources' => [
                    ['table' => 'projects', 'date_column' => 'due_date', 'title_column' => 'name', 'color' => '#10b981', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=projects&id={id}', 'icon' => 'assets/icons/account_tree.png', 'notified_users' => []],
                    ['table' => 'tasks', 'date_column' => 'due_date', 'title_column' => 'title', 'color' => '#3b82f6', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=tasks&id={id}', 'icon' => 'assets/icons/checklist_rtl.png', 'notified_users' => []],
                ],
                'workflows' => [
                    ['id' => 'wf_demo_tasks_001', 'title' => 'New Project Setup', 'icon' => 'assets/icons/account_tree.png', 'description' => 'Tasks: create project → add tasks → log time.', 'steps' => [
                        ['title' => 'New Project', 'table' => 'projects',  'foreign_key' => '', 'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Add Tasks',   'table' => 'tasks',     'foreign_key' => 'project_id', 'link_to_step' => 0, 'allow_multiple' => true],
                        ['title' => 'Log Time',    'table' => 'time_logs', 'foreign_key' => 'task_id', 'link_to_step' => 1, 'allow_multiple' => true],
                    ]],
                ],
                'views' => [
                    'v_demo_tasks_summary' => ['display_name' => 'Task Summary', 'menu_name' => 'Summary', 'icon' => 'assets/icons/checklist_rtl.png', 'hidden' => false, 'description' => 'Task count by project & status.', 'columns' => [
                        'project'    => ['display_name' => 'Project'],
                        'status'     => ['display_name' => 'Status'],
                        'task_count' => ['display_name' => 'Count'],
                    ], 'drill_down' => ['enabled' => false]],
                ],
            ];

        default:
            throw new \InvalidArgumentException("Unknown demo type: {$type}");
    }
}
