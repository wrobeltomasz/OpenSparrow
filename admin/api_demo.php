<?php

declare(strict_types=1);

if (!defined('DEMO_MODE')) {
    http_response_code(403);
    exit;
}

/* ── Demo: status ────────────────────────────────────────────────── */
if ($action === 'demo_status') {
    header('Content-Type: application/json');
    $metaPath = realpath(__DIR__ . '/../config') . '/demo_meta.json';
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

        $configDir = realpath(__DIR__ . '/../config');

        // schema.json
        $schemaPath = $configDir . '/schema.json';
        $schemaCfg  = file_exists($schemaPath) ? (json_decode(file_get_contents($schemaPath), true) ?? []) : [];
        if (!isset($schemaCfg['tables']) || !is_array($schemaCfg['tables'])) {
            $schemaCfg['tables'] = [];
        }
        foreach ($demoData['schema_tables'] as $key => $def) {
            $schemaCfg['tables'][$key] = $def;
        }
        file_put_contents($schemaPath, json_encode($schemaCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // dashboard.json
        $dashPath = $configDir . '/dashboard.json';
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
        $calPath  = $configDir . '/calendar.json';
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
        $wfPath = $configDir . '/workflows.json';
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
        $viewsPath = $configDir . '/views.json';
        $viewsCfg  = file_exists($viewsPath) ? (json_decode(file_get_contents($viewsPath), true) ?? []) : [];
        if (!isset($viewsCfg['views']) || !is_array($viewsCfg['views'])) {
            $viewsCfg['views'] = [];
        }
        foreach ($demoData['views'] as $key => $def) {
            $viewsCfg['views'][$key] = $def;
        }
        file_put_contents($viewsPath, json_encode($viewsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // menu.json — apply nested menu layout from demo definition
        $menuKeys = [];
        if (!empty($demoData['menu_items']) && is_array($demoData['menu_items'])) {
            $menuPath = $configDir . '/menu.json';
            $menuCfg  = file_exists($menuPath) ? (json_decode(file_get_contents($menuPath), true) ?? []) : [];
            if (!isset($menuCfg['items']) || !is_array($menuCfg['items'])) {
                $menuCfg['items'] = [];
            }
            foreach ($demoData['menu_items'] as $entry) {
                $k = $entry['key'] ?? '';
                if ($k === '') continue;
                $menuKeys[] = $k;
                $menuCfg['items'] = array_values(
                    array_filter($menuCfg['items'], fn($i) => ($i['key'] ?? '') !== $k)
                );
                $menuCfg['items'][] = $entry;
            }
            file_put_contents($menuPath, json_encode($menuCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

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
            'menu_keys'    => $menuKeys,
        ];
        file_put_contents(
            $configDir . '/demo_meta.json',
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

    $configDir = realpath(__DIR__ . '/../config');
    $metaPath = $configDir . '/demo_meta.json';
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
        $schemaPath = $configDir . '/schema.json';
        if (file_exists($schemaPath)) {
            $cfg = json_decode(file_get_contents($schemaPath), true) ?? [];
            // Collect hidden junction tables referenced by demo tables before removing them
            $m2mJunctions = [];
            foreach ($meta['tables'] ?? [] as $t) {
                foreach ($cfg['tables'][$t]['many_to_many'] ?? [] as $m2m) {
                    $jt = $m2m['junction_table'] ?? '';
                    if ($jt && !empty($cfg['tables'][$jt]['hidden'])) {
                        $m2mJunctions[] = $jt;
                    }
                }
                unset($cfg['tables'][$t]);
            }
            // Remove orphaned hidden junction tables (not tracked in meta, added via M2M Builder)
            foreach ($m2mJunctions as $jt) {
                if (isset($cfg['tables'][$jt])) {
                    $stillUsed = false;
                    foreach ($cfg['tables'] as $tCfg) {
                        foreach ($tCfg['many_to_many'] ?? [] as $m) {
                            if (($m['junction_table'] ?? '') === $jt) {
                                $stillUsed = true;
                                break 2;
                            }
                        }
                    }
                    if (!$stillUsed) {
                        unset($cfg['tables'][$jt]);
                    }
                }
            }
            if (empty($cfg['tables'])) {
                @unlink($schemaPath);
            } else {
                file_put_contents($schemaPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        // Clean dashboard.json (delete if empty)
        $dashPath = $configDir . '/dashboard.json';
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
        $calPath = $configDir . '/calendar.json';
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
        $wfPath = $configDir . '/workflows.json';
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
        $viewsPath = $configDir . '/views.json';
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

        // Clean menu.json (delete if empty)
        $menuPath = $configDir . '/menu.json';
        if (file_exists($menuPath)) {
            $cfg  = json_decode(file_get_contents($menuPath), true) ?? [];
            $keys = $meta['menu_keys'] ?? [];
            if (!empty($keys) && isset($cfg['items']) && is_array($cfg['items'])) {
                $cfg['items'] = array_values(
                    array_filter($cfg['items'], fn($i) => !in_array($i['key'] ?? '', $keys, true))
                );
            }
            if (empty($cfg['items'])) {
                @unlink($menuPath);
            } else {
                file_put_contents($menuPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
                'view_names' => ['v_demo_crm_pipeline', 'v_demo_crm_leads_funnel', 'v_demo_crm_revenue', 'v_demo_crm_assets_by_category'],
                'ddl' => [
                    'CREATE SCHEMA IF NOT EXISTS spw_crm',
                    "CREATE TABLE IF NOT EXISTS spw_crm.companies (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, industry VARCHAR(100), website VARCHAR(255), phone VARCHAR(50), email VARCHAR(255), created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.contacts (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255), phone VARCHAR(50), position VARCHAR(100), created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.deals (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, title VARCHAR(255) NOT NULL, value NUMERIC(12,2), stage VARCHAR(50) DEFAULT 'Lead', expected_close DATE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.activities (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE CASCADE, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, type VARCHAR(50) DEFAULT 'Call', notes TEXT, scheduled_at TIMESTAMP, done BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.leads (id SERIAL PRIMARY KEY, source VARCHAR(50) DEFAULT 'Web', first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255), phone VARCHAR(50), company_name VARCHAR(255), status VARCHAR(50) DEFAULT 'New', converted_contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.products (id SERIAL PRIMARY KEY, sku VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, description TEXT, unit_price NUMERIC(12,2) DEFAULT 0, category VARCHAR(100), active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.quotes (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE CASCADE, quote_number VARCHAR(50) NOT NULL UNIQUE, status VARCHAR(50) DEFAULT 'Draft', valid_until DATE, subtotal NUMERIC(12,2) DEFAULT 0, tax NUMERIC(12,2) DEFAULT 0, total NUMERIC(12,2) DEFAULT 0, notes TEXT, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.invoices (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE SET NULL, quote_id INTEGER REFERENCES spw_crm.quotes(id) ON DELETE SET NULL, invoice_number VARCHAR(50) NOT NULL UNIQUE, status VARCHAR(50) DEFAULT 'Draft', issue_date DATE NOT NULL, due_date DATE NOT NULL, amount_net NUMERIC(12,2) DEFAULT 0, amount_tax NUMERIC(12,2) DEFAULT 0, amount_total NUMERIC(12,2) DEFAULT 0, paid_at TIMESTAMP, notes TEXT, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.assets (id SERIAL PRIMARY KEY, asset_tag VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, category VARCHAR(50) DEFAULT 'Hardware', purchase_date DATE, purchase_price NUMERIC(12,2) DEFAULT 0, current_value NUMERIC(12,2) DEFAULT 0, depreciation_method VARCHAR(50) DEFAULT 'Straight Line', assigned_contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, location VARCHAR(255), warranty_end DATE, next_inspection_date DATE, status VARCHAR(50) DEFAULT 'Active', notes TEXT, created_at TIMESTAMP DEFAULT NOW())",
                    "CREATE TABLE IF NOT EXISTS spw_crm.product_contacts (id SERIAL PRIMARY KEY, product_id INTEGER REFERENCES spw_crm.products(id) ON DELETE CASCADE, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE CASCADE, interested_at TIMESTAMP DEFAULT NOW())",
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_crm_pipeline AS SELECT stage, COUNT(*) AS deal_count, COALESCE(SUM(value), 0) AS total_value FROM spw_crm.deals GROUP BY stage ORDER BY stage',
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_crm_leads_funnel AS SELECT status, COUNT(*) AS lead_count FROM spw_crm.leads GROUP BY status ORDER BY status',
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_crm_revenue AS SELECT status, COUNT(*) AS invoice_count, COALESCE(SUM(amount_total), 0) AS total FROM spw_crm.invoices GROUP BY status ORDER BY status',
                    'CREATE OR REPLACE VIEW ' . pg_ident($appSchema) . '.v_demo_crm_assets_by_category AS SELECT category, COUNT(*) AS asset_count, COALESCE(SUM(current_value), 0) AS total_value FROM spw_crm.assets GROUP BY category ORDER BY category',
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
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (1, 1, 'Email', 'Sent revised pricing sheet', NOW() - INTERVAL '12 days', true)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (2, 3, 'Call', 'Quarterly check-in call', NOW() + INTERVAL '7 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (3, 4, 'Meeting', 'Architecture walkthrough on-site', NOW() + INTERVAL '10 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (4, 5, 'Task', 'Prepare renewal contract draft', NOW() - INTERVAL '8 days', true)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (5, 6, 'Email', 'Introductory product overview', NOW() + INTERVAL '14 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (6, 2, 'Call', 'Discovery call with procurement', NOW() + INTERVAL '2 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (1, 2, 'Meeting', 'Demo of new reporting features', NOW() + INTERVAL '17 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (2, 3, 'Note', 'Stakeholder map updated', NOW() - INTERVAL '15 days', true)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (3, 4, 'Task', 'Send SOC2 documentation pack', NOW() + INTERVAL '5 days', false)",
                    "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (4, 5, 'Email', 'Confirm onboarding schedule', NOW() + INTERVAL '21 days', false)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Web', 'Olivia', 'Hayes', 'olivia.h@northwind.io', '+1-555-3001', 'Northwind Traders', 'New', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Referral', 'Marcus', 'Bennett', 'marcus.b@apexlogi.com', '+1-555-3002', 'Apex Logistics', 'Contacted', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Event', 'Sofia', 'Kowalski', 'sofia.k@brightsoft.eu', '+44-20-555-0103', 'BrightSoft EU', 'Qualified', 1)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Cold Call', 'Ethan', 'Park', 'ethan.p@nextstride.io', '+1-555-3004', 'NextStride', 'New', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Ads', 'Aisha', 'Khan', 'aisha.k@summitcloud.com', '+1-555-3005', 'Summit Cloud', 'Contacted', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Web', 'Lucas', 'Müller', 'lucas.m@helixdata.de', '+49-30-555-0106', 'Helix Data GmbH', 'Lost', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Referral', 'Maya', 'Patel', 'maya.p@kinetic-labs.com', '+1-555-3007', 'Kinetic Labs', 'Qualified', 2)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Event', 'Noah', 'Andersson', 'noah.a@fjordtech.no', '+47-22-555-0108', 'Fjord Tech', 'New', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Other', 'Chloe', 'Dubois', 'chloe.d@parisretail.fr', '+33-1-5555-0109', 'Paris Retail SA', 'Contacted', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Web', 'Hiroshi', 'Tanaka', 'h.tanaka@sakuranet.jp', '+81-3-5555-0110', 'SakuraNet KK', 'New', NULL)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Ads', 'Isabella', 'Romano', 'i.romano@milanodigital.it', '+39-02-555-0111', 'Milano Digital', 'Qualified', 3)",
                    "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id) VALUES ('Cold Call', 'Daniel', 'Wright', 'daniel.w@blackpine.co', '+1-555-3012', 'Black Pine Holdings', 'Lost', NULL)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SW-CORE-01', 'OpenSparrow Core License', 'Single-tenant production license', 4900.00, 'Software', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SW-CORE-ENT', 'OpenSparrow Enterprise License', 'Multi-tenant + priority support', 14900.00, 'Software', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SVC-IMPL-S', 'Implementation — Standard', 'Up to 5 tables, 1 dashboard, 10 hrs', 3500.00, 'Service', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SVC-IMPL-XL', 'Implementation — Enterprise', 'Custom schema, integrations, 60 hrs', 18000.00, 'Service', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SVC-TRAIN', 'Admin Training Workshop', 'Full-day on-site or remote', 1800.00, 'Service', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SUP-BASIC', 'Basic Support — Annual', 'Email support, 48h SLA', 1200.00, 'Support', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SUP-PRIO', 'Priority Support — Annual', 'Phone + email, 4h SLA', 4800.00, 'Support', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SUP-PREM', 'Premium Support — Annual', '24/7 + dedicated CSM', 12000.00, 'Support', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('HW-BAR-01', 'Barcode Scanner Bundle', 'USB scanner + 1y warranty', 320.00, 'Hardware', TRUE)",
                    "INSERT INTO spw_crm.products (sku, name, description, unit_price, category, active) VALUES ('SW-ADDON-RPT', 'Advanced Reporting Add-on', 'Legacy module, deprecated', 900.00, 'Software', FALSE)",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (1, 'Q-2026-001', 'Sent', CURRENT_DATE + INTERVAL '14 days', 45000.00, 10350.00, 55350.00, 'Standard 23% VAT. Quote valid 14 days.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (2, 'Q-2026-002', 'Draft', CURRENT_DATE + INTERVAL '30 days', 120000.00, 27600.00, 147600.00, 'Awaiting legal review of payment terms.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (3, 'Q-2026-003', 'Accepted', CURRENT_DATE + INTERVAL '5 days', 85000.00, 19550.00, 104550.00, 'PO #PO-7782 received.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (4, 'Q-2026-004', 'Accepted', CURRENT_DATE - INTERVAL '5 days', 35000.00, 8050.00, 43050.00, 'Signed and archived.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (5, 'Q-2026-005', 'Sent', CURRENT_DATE + INTERVAL '21 days', 55000.00, 12650.00, 67650.00, 'Customer requested 14-day payment terms.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (6, 'Q-2026-006', 'Expired', CURRENT_DATE - INTERVAL '20 days', 25000.00, 5750.00, 30750.00, 'No response — follow up before reissue.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (1, 'Q-2026-007', 'Rejected', CURRENT_DATE + INTERVAL '3 days', 65000.00, 14950.00, 79950.00, 'Customer chose competitor.')",
                    "INSERT INTO spw_crm.quotes (deal_id, quote_number, status, valid_until, subtotal, tax, total, notes) VALUES (5, 'Q-2026-008', 'Sent', CURRENT_DATE + INTERVAL '10 days', 42000.00, 9660.00, 51660.00, 'Discount approved by sales lead.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (4, 4, 'INV-2026-001', 'Paid', CURRENT_DATE - INTERVAL '40 days', CURRENT_DATE - INTERVAL '10 days', 35000.00, 8050.00, 43050.00, NOW() - INTERVAL '12 days', 'Wire transfer received.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (3, 3, 'INV-2026-002', 'Paid', CURRENT_DATE - INTERVAL '30 days', CURRENT_DATE - INTERVAL '2 days', 85000.00, 19550.00, 104550.00, NOW() - INTERVAL '3 days', 'Paid against PO #PO-7782.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (4, NULL, 'INV-2026-003', 'Paid', CURRENT_DATE - INTERVAL '90 days', CURRENT_DATE - INTERVAL '60 days', 12000.00, 2760.00, 14760.00, NOW() - INTERVAL '61 days', 'Annual support renewal.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (4, NULL, 'INV-2026-004', 'Paid', CURRENT_DATE - INTERVAL '120 days', CURRENT_DATE - INTERVAL '90 days', 8000.00, 1840.00, 9840.00, NOW() - INTERVAL '92 days', 'Phase 1 milestone invoice.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (1, 1, 'INV-2026-005', 'Sent', CURRENT_DATE - INTERVAL '5 days', CURRENT_DATE + INTERVAL '25 days', 45000.00, 10350.00, 55350.00, NULL, 'Net-30 terms.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (2, 2, 'INV-2026-006', 'Sent', CURRENT_DATE - INTERVAL '2 days', CURRENT_DATE + INTERVAL '14 days', 60000.00, 13800.00, 73800.00, NULL, 'Milestone 1 of digital transformation.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (3, 3, 'INV-2026-007', 'Sent', CURRENT_DATE, CURRENT_DATE + INTERVAL '7 days', 25000.00, 5750.00, 30750.00, NULL, 'Second milestone — cloud rollout.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (5, 5, 'INV-2026-008', 'Overdue', CURRENT_DATE - INTERVAL '50 days', CURRENT_DATE - INTERVAL '15 days', 27500.00, 6325.00, 33825.00, NULL, 'Reminder sent twice. Escalate to AR.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (6, NULL, 'INV-2026-009', 'Overdue', CURRENT_DATE - INTERVAL '35 days', CURRENT_DATE - INTERVAL '5 days', 12500.00, 2875.00, 15375.00, NULL, 'Past due — contact finance.')",
                    "INSERT INTO spw_crm.invoices (deal_id, quote_id, invoice_number, status, issue_date, due_date, amount_net, amount_tax, amount_total, paid_at, notes) VALUES (2, NULL, 'INV-2026-010', 'Draft', CURRENT_DATE, CURRENT_DATE + INTERVAL '30 days', 60000.00, 13800.00, 73800.00, NULL, 'Pending PM approval.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00001', 'Dell PowerEdge R750 #1', 'Hardware', CURRENT_DATE - INTERVAL '2 years', 9500.00, 5800.00, 'Straight Line', 1, 'DC Rack A1', CURRENT_DATE + INTERVAL '1 year', CURRENT_DATE + INTERVAL '60 days', 'Active', 'Primary production node.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00002', 'Dell PowerEdge R750 #2', 'Hardware', CURRENT_DATE - INTERVAL '2 years', 9500.00, 5800.00, 'Straight Line', 1, 'DC Rack A2', CURRENT_DATE + INTERVAL '1 year', CURRENT_DATE + INTERVAL '60 days', 'Active', 'Failover production node.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00003', 'MacBook Pro 16\" M3', 'Hardware', CURRENT_DATE - INTERVAL '6 months', 3200.00, 2800.00, 'Straight Line', 2, 'HQ Office 2A', CURRENT_DATE + INTERVAL '2 years', NULL, 'Active', 'Assigned to Sarah Johnson.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00004', 'Cisco Catalyst 9300 Switch', 'Hardware', CURRENT_DATE - INTERVAL '3 years', 4800.00, 1800.00, 'Straight Line', NULL, 'DC Network Closet', CURRENT_DATE - INTERVAL '60 days', CURRENT_DATE + INTERVAL '30 days', 'Maintenance', 'Warranty expired — review replacement.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00005', 'Toyota Hilux — Fleet #1', 'Vehicle', CURRENT_DATE - INTERVAL '4 years', 32000.00, 18500.00, 'Declining', 3, 'Warsaw Depot', CURRENT_DATE - INTERVAL '15 days', CURRENT_DATE + INTERVAL '45 days', 'Active', 'Field service team vehicle.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00006', 'Ford Transit Custom — Fleet #2', 'Vehicle', CURRENT_DATE - INTERVAL '1 year', 38000.00, 32000.00, 'Declining', NULL, 'Berlin Depot', CURRENT_DATE + INTERVAL '2 years', CURRENT_DATE + INTERVAL '180 days', 'Active', 'Delivery van.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00007', 'HQ Office Building — Warsaw', 'Real Estate', CURRENT_DATE - INTERVAL '8 years', 1200000.00, 1450000.00, 'None', NULL, 'Warsaw, Marszalkowska 100', NULL, CURRENT_DATE + INTERVAL '120 days', 'Active', 'Capitalized at fair market value.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00008', 'Industrial 3D Printer Form 4L', 'Equipment', CURRENT_DATE - INTERVAL '1 year', 18000.00, 14500.00, 'Straight Line', 4, 'R&D Lab', CURRENT_DATE + INTERVAL '90 days', CURRENT_DATE + INTERVAL '30 days', 'Active', 'Resin replenishment due quarterly.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00009', 'Forklift Toyota 8FBE15', 'Equipment', CURRENT_DATE - INTERVAL '5 years', 22000.00, 8500.00, 'Declining', NULL, 'Warehouse W1', CURRENT_DATE - INTERVAL '2 years', CURRENT_DATE + INTERVAL '15 days', 'Active', 'Annual safety inspection required.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00010', 'JetBrains All Products Pack — Team 25', 'Software License', CURRENT_DATE - INTERVAL '4 months', 18750.00, 12500.00, 'Straight Line', NULL, 'Floating licenses', CURRENT_DATE + INTERVAL '8 months', NULL, 'Active', 'Renews annually.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00011', 'Conference Phone Polycom Trio 8800', 'Hardware', CURRENT_DATE - INTERVAL '6 years', 1100.00, 0.00, 'Straight Line', NULL, 'HQ Boardroom (decommissioned)', CURRENT_DATE - INTERVAL '3 years', NULL, 'Retired', 'Replaced by Teams Rooms in 2025.')",
                    "INSERT INTO spw_crm.assets (asset_tag, name, category, purchase_date, purchase_price, current_value, depreciation_method, assigned_contact_id, location, warranty_end, next_inspection_date, status, notes) VALUES ('AST-00012', 'iPad Pro 12.9\" — Field Tablet #7', 'Hardware', CURRENT_DATE - INTERVAL '18 months', 1500.00, 0.00, 'Straight Line', NULL, 'Last seen — Berlin Depot', CURRENT_DATE + INTERVAL '6 months', NULL, 'Lost', 'Reported missing 2026-04-22. Insurance claim filed.')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (1, 1, NOW() - INTERVAL '15 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (1, 2, NOW() - INTERVAL '8 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (1, 3, NOW() - INTERVAL '3 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (2, 1, NOW() - INTERVAL '20 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (2, 4, NOW() - INTERVAL '10 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (3, 2, NOW() - INTERVAL '5 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (3, 5, NOW() - INTERVAL '1 day')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (4, 4, NOW() - INTERVAL '7 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (5, 3, NOW() - INTERVAL '12 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (5, 6, NOW() - INTERVAL '2 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (6, 1, NOW() - INTERVAL '9 days')",
                    "INSERT INTO spw_crm.product_contacts (product_id, contact_id, interested_at) VALUES (7, 5, NOW() - INTERVAL '4 days')",
                ],
                'schema_tables' => [
                    'companies' => ['display_name' => 'Companies', 'schema' => 'spw_crm', 'icon' => 'assets/icons/apartment.png', 'columns' => [
                        'id'         => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'ID', 'description' => 'Unique company identifier'],
                        'name'       => ['type' => 'text',   'show_in_grid' => true,  'display_name' => 'Company Name', 'not_null' => true, 'description' => 'Official company name'],
                        'industry'   => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Industry', 'description' => 'Industry or sector the company operates in'],
                        'website'    => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Website', 'description' => 'Company website URL'],
                        'phone'      => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Phone', 'description' => 'Main company phone number'],
                        'email'      => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Email', 'description' => 'Company email address'],
                        'created_at' => ['type' => 'timestamp', 'show_in_grid' => true,  'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when company record was created'],
                    ], 'subtables' => [
                        ['table' => 'contacts', 'foreign_key' => 'company_id', 'label' => 'Contacts', 'columns_to_show' => ['first_name', 'last_name', 'email', 'position']],
                        ['table' => 'deals',    'foreign_key' => 'company_id', 'label' => 'Deals',    'columns_to_show' => ['title', 'stage', 'value', 'expected_close']],
                    ]],
                    'contacts' => ['display_name' => 'Contacts', 'schema' => 'spw_crm', 'icon' => 'assets/icons/person.png', 'columns' => [
                        'id'         => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique contact identifier'],
                        'company_id' => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Company', 'description' => 'Company this contact belongs to'],
                        'first_name' => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'First Name', 'not_null' => true, 'description' => 'Contact first name'],
                        'last_name'  => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Last Name',  'not_null' => true, 'description' => 'Contact last name'],
                        'email'      => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Email', 'description' => 'Contact email address'],
                        'phone'      => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Phone', 'description' => 'Contact phone number'],
                        'position'   => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Position', 'description' => 'Job title or position at company'],
                        'created_at' => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when contact record was created'],
                    ], 'foreign_keys' => [
                        'company_id' => ['reference_table' => 'companies', 'reference_column' => 'id', 'display_column' => 'name'],
                    ], 'subtables' => [
                        ['table' => 'activities', 'foreign_key' => 'contact_id', 'label' => 'Activities', 'columns_to_show' => ['type', 'scheduled_at', 'done']],
                    ]],
                    'deals' => ['display_name' => 'Deals', 'schema' => 'spw_crm', 'icon' => 'assets/icons/point_of_sale.png', 'columns' => [
                        'id'             => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique deal identifier'],
                        'company_id'     => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Company', 'description' => 'Company associated with this deal'],
                        'contact_id'     => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Primary Contact', 'description' => 'Primary contact for this deal'],
                        'title'          => ['type' => 'text',   'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Title', 'description' => 'Deal name or description'],
                        'value'          => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Value', 'description' => 'Estimated deal value in currency units'],
                        'stage'          => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Lead', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'], 'enum_colors' => ['Lead' => '#d1d5db', 'Qualified' => '#93c5fd', 'Proposal' => '#fcd34d', 'Negotiation' => '#fcd34d', 'Won' => '#6ee7b7', 'Lost' => '#f87171'], 'display_name' => 'Stage', 'description' => 'Current stage in sales pipeline'],
                        'expected_close' => ['type' => 'date',   'show_in_grid' => true, 'display_name' => 'Expected Close', 'description' => 'Projected closing date'],
                        'created_at'     => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when deal record was created'],
                    ], 'foreign_keys' => [
                        'company_id' => ['reference_table' => 'companies', 'reference_column' => 'id', 'display_column' => 'name'],
                        'contact_id' => ['reference_table' => 'contacts',  'reference_column' => 'id', 'display_column' => 'first_name'],
                    ], 'subtables' => [
                        ['table' => 'activities', 'foreign_key' => 'deal_id', 'label' => 'Activities', 'columns_to_show' => ['type', 'scheduled_at', 'done', 'notes']],
                        ['table' => 'quotes',     'foreign_key' => 'deal_id', 'label' => 'Quotes',     'columns_to_show' => ['quote_number', 'status', 'total', 'valid_until']],
                        ['table' => 'invoices',   'foreign_key' => 'deal_id', 'label' => 'Invoices',   'columns_to_show' => ['invoice_number', 'status', 'amount_total', 'due_date']],
                    ]],
                    'activities' => ['display_name' => 'Activities', 'schema' => 'spw_crm', 'icon' => 'assets/icons/calendar.png', 'columns' => [
                        'id'           => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique activity identifier'],
                        'deal_id'      => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Deal', 'description' => 'Deal this activity is associated with'],
                        'contact_id'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Contact', 'description' => 'Contact involved in this activity'],
                        'type'         => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Call', 'Email', 'Meeting', 'Task', 'Note'], 'enum_colors' => ['Call' => '#93c5fd', 'Email' => '#6ee7b7', 'Meeting' => '#fcd34d', 'Task' => '#c4b5fd', 'Note' => '#d1d5db'], 'display_name' => 'Type', 'description' => 'Type of activity performed'],
                        'notes'        => ['type' => 'text',    'show_in_grid' => false, 'display_name' => 'Notes', 'description' => 'Detailed notes or comments about the activity'],
                        'scheduled_at' => ['type' => 'timestamp', 'show_in_grid' => true, 'display_name' => 'Scheduled At', 'description' => 'Date and time activity is scheduled or occurred'],
                        'done'         => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#6ee7b7', 'false' => '#f87171'], 'display_name' => 'Done', 'description' => 'Whether activity is completed'],
                        'created_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when activity record was created'],
                    ], 'foreign_keys' => [
                        'deal_id'    => ['reference_table' => 'deals',    'reference_column' => 'id', 'display_column' => 'title'],
                        'contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => 'last_name'],
                    ]],
                    'leads' => ['display_name' => 'Leads', 'schema' => 'spw_crm', 'icon' => 'assets/icons/person_text.png', 'columns' => [
                        'id'                   => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique lead identifier'],
                        'source'               => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Web', 'Referral', 'Cold Call', 'Event', 'Ads', 'Other'], 'enum_colors' => ['Web' => '#93c5fd', 'Referral' => '#6ee7b7', 'Cold Call' => '#d1d5db', 'Event' => '#fcd34d', 'Ads' => '#c4b5fd', 'Other' => '#d1d5db'], 'display_name' => 'Source', 'description' => 'How this lead was acquired'],
                        'first_name'           => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'First Name', 'not_null' => true, 'description' => 'Lead first name'],
                        'last_name'            => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Last Name',  'not_null' => true, 'description' => 'Lead last name'],
                        'email'                => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Email', 'description' => 'Lead email address'],
                        'phone'                => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Phone', 'description' => 'Lead phone number'],
                        'company_name'         => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Company',    'description' => 'Free-text company name (not yet linked to companies table)'],
                        'status'               => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['New', 'Contacted', 'Qualified', 'Lost'], 'enum_colors' => ['New' => '#93c5fd', 'Contacted' => '#fcd34d', 'Qualified' => '#6ee7b7', 'Lost' => '#f87171'], 'display_name' => 'Status', 'description' => 'Lead qualification status'],
                        'converted_contact_id' => ['type' => 'number',  'show_in_grid' => false, 'display_name' => 'Converted To', 'description' => 'Contact record created when lead was converted'],
                        'created_at'           => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when lead record was created'],
                    ], 'foreign_keys' => [
                        'converted_contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => 'last_name'],
                    ]],
                    'products' => ['display_name' => 'Products', 'schema' => 'spw_crm', 'icon' => 'assets/icons/shopping_cart.png', 'columns' => [
                        'id'          => ['type' => 'number',  'display_name' => 'ID', 'description' => 'Unique product identifier'],
                        'sku'         => ['type' => 'text',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'SKU', 'description' => 'Stock Keeping Unit — unique product code'],
                        'name'        => ['type' => 'text',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Product or service name'],
                        'description' => ['type' => 'text',    'show_in_grid' => false, 'display_name' => 'Description', 'description' => 'Detailed product description'],
                        'unit_price'  => ['type' => 'number',  'show_in_grid' => true, 'display_name' => 'Unit Price', 'description' => 'Standard list price per unit'],
                        'category'    => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Software', 'Service', 'Support', 'Hardware'], 'enum_colors' => ['Software' => '#93c5fd', 'Service' => '#6ee7b7', 'Support' => '#fcd34d', 'Hardware' => '#d1d5db'], 'display_name' => 'Category', 'description' => 'Product category'],
                        'active'      => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#6ee7b7', 'false' => '#f87171'], 'display_name' => 'Active', 'description' => 'Whether product is currently sellable'],
                        'created_at'  => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when product record was created'],
                    ], 'many_to_many' => [
                        ['label' => 'Interested Contacts', 'junction_table' => 'product_contacts', 'self_fk' => 'product_id', 'other_fk' => 'contact_id', 'other_table' => 'contacts', 'display_column' => 'last_name'],
                    ]],
                    'quotes' => ['display_name' => 'Quotes', 'schema' => 'spw_crm', 'icon' => 'assets/icons/ballot.png', 'columns' => [
                        'id'           => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique quote identifier'],
                        'deal_id'      => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Deal', 'description' => 'Deal this quote is attached to'],
                        'quote_number' => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Quote #', 'not_null' => true, 'description' => 'Human-readable quote identifier'],
                        'status'       => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired'], 'enum_colors' => ['Draft' => '#d1d5db', 'Sent' => '#93c5fd', 'Accepted' => '#6ee7b7', 'Rejected' => '#f87171', 'Expired' => '#fcd34d'], 'display_name' => 'Status', 'description' => 'Quote lifecycle status'],
                        'valid_until'  => ['type' => 'date',   'show_in_grid' => true, 'display_name' => 'Valid Until', 'description' => 'Expiration date of the offer'],
                        'subtotal'     => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'Subtotal', 'description' => 'Sum of line items before tax'],
                        'tax'          => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'Tax', 'description' => 'Tax amount'],
                        'total'        => ['type' => 'number', 'show_in_grid' => true,  'display_name' => 'Total', 'description' => 'Grand total payable'],
                        'notes'        => ['type' => 'text',   'show_in_grid' => false, 'display_name' => 'Notes', 'description' => 'Internal notes or customer-facing comments'],
                        'created_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when quote record was created'],
                    ], 'foreign_keys' => [
                        'deal_id' => ['reference_table' => 'deals', 'reference_column' => 'id', 'display_column' => 'title'],
                    ], 'subtables' => [
                        ['table' => 'invoices', 'foreign_key' => 'quote_id', 'label' => 'Invoices', 'columns_to_show' => ['invoice_number', 'status', 'amount_total', 'due_date']],
                    ]],
                    'invoices' => ['display_name' => 'Invoices', 'schema' => 'spw_crm', 'icon' => 'assets/icons/file_present.png', 'columns' => [
                        'id'             => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique invoice identifier'],
                        'deal_id'        => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Deal', 'description' => 'Deal this invoice belongs to (optional)'],
                        'quote_id'       => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Quote', 'description' => 'Quote this invoice was generated from (optional)'],
                        'invoice_number' => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Invoice #', 'not_null' => true, 'description' => 'Human-readable invoice identifier'],
                        'status'         => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Draft', 'Sent', 'Paid', 'Overdue', 'Cancelled'], 'enum_colors' => ['Draft' => '#d1d5db', 'Sent' => '#93c5fd', 'Paid' => '#6ee7b7', 'Overdue' => '#f87171', 'Cancelled' => '#d1d5db'], 'display_name' => 'Status', 'description' => 'Invoice lifecycle status'],
                        'issue_date'     => ['type' => 'date',   'show_in_grid' => true, 'display_name' => 'Issued', 'not_null' => true, 'description' => 'Date the invoice was issued'],
                        'due_date'       => ['type' => 'date',   'show_in_grid' => true, 'display_name' => 'Due',    'not_null' => true, 'description' => 'Date payment is due'],
                        'amount_net'     => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'Net',   'description' => 'Net amount before tax'],
                        'amount_tax'     => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'Tax',   'description' => 'Tax amount'],
                        'amount_total'   => ['type' => 'number', 'show_in_grid' => true,  'display_name' => 'Total', 'description' => 'Total amount payable'],
                        'paid_at'        => ['type' => 'timestamp', 'show_in_grid' => false, 'display_name' => 'Paid At', 'description' => 'Timestamp of payment receipt (NULL when unpaid)'],
                        'notes'          => ['type' => 'text',   'show_in_grid' => false, 'display_name' => 'Notes', 'description' => 'Internal notes about the invoice'],
                        'created_at'     => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when invoice record was created'],
                    ], 'foreign_keys' => [
                        'deal_id'  => ['reference_table' => 'deals',  'reference_column' => 'id', 'display_column' => 'title'],
                        'quote_id' => ['reference_table' => 'quotes', 'reference_column' => 'id', 'display_column' => 'quote_number'],
                    ]],
                    'assets' => ['display_name' => 'Assets', 'schema' => 'spw_crm', 'icon' => 'assets/icons/database.png', 'columns' => [
                        'id'                   => ['type' => 'number',  'display_name' => 'ID', 'description' => 'Unique asset identifier'],
                        'asset_tag'            => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Tag', 'not_null' => true, 'description' => 'Inventory tag — printed/scanned identifier'],
                        'name'                 => ['type' => 'text',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Asset name or description'],
                        'category'             => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Hardware', 'Vehicle', 'Real Estate', 'Equipment', 'Software License', 'Other'], 'enum_colors' => ['Hardware' => '#d1d5db', 'Vehicle' => '#93c5fd', 'Real Estate' => '#c4b5fd', 'Equipment' => '#6ee7b7', 'Software License' => '#93c5fd', 'Other' => '#d1d5db'], 'display_name' => 'Category', 'description' => 'Asset classification'],
                        'purchase_date'        => ['type' => 'date',    'show_in_grid' => false, 'display_name' => 'Purchased', 'description' => 'Date the asset was acquired'],
                        'purchase_price'       => ['type' => 'number',  'show_in_grid' => false, 'display_name' => 'Purchase Price', 'description' => 'Original acquisition cost'],
                        'current_value'        => ['type' => 'number',  'show_in_grid' => true,  'display_name' => 'Current Value', 'description' => 'Book value after depreciation'],
                        'depreciation_method'  => ['type' => 'enum',    'show_in_grid' => false, 'options' => ['Straight Line', 'Declining', 'None'], 'enum_colors' => ['Straight Line' => '#93c5fd', 'Declining' => '#fcd34d', 'None' => '#d1d5db'], 'display_name' => 'Depreciation Method', 'description' => 'Accounting depreciation method'],
                        'assigned_contact_id'  => ['type' => 'number',  'show_in_grid' => true,  'display_name' => 'Assigned To', 'description' => 'Contact responsible for the asset (optional)'],
                        'location'             => ['type' => 'text',    'show_in_grid' => true,  'display_name' => 'Location', 'description' => 'Physical or logical location of the asset'],
                        'warranty_end'         => ['type' => 'date',    'show_in_grid' => true,  'display_name' => 'Warranty Until', 'description' => 'Date manufacturer/service warranty expires'],
                        'next_inspection_date' => ['type' => 'date',    'show_in_grid' => false, 'display_name' => 'Next Inspection', 'description' => 'Date of next scheduled inspection/maintenance'],
                        'status'               => ['type' => 'enum',    'show_in_grid' => true,  'options' => ['Active', 'Maintenance', 'Retired', 'Lost'], 'enum_colors' => ['Active' => '#6ee7b7', 'Maintenance' => '#fcd34d', 'Retired' => '#d1d5db', 'Lost' => '#f87171'], 'display_name' => 'Status', 'description' => 'Operational status of the asset'],
                        'notes'                => ['type' => 'text',    'show_in_grid' => false, 'display_name' => 'Notes', 'description' => 'Free-text notes about condition, history, claims'],
                        'created_at'           => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when asset record was created'],
                    ], 'foreign_keys' => [
                        'assigned_contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => 'last_name'],
                    ]],
                    'product_contacts' => ['display_name' => 'Product–Contacts', 'schema' => 'spw_crm', 'hidden' => true, 'columns' => [
                        'id'         => ['display_name' => 'ID',         'type' => 'number', 'not_null' => true, 'readonly' => true,  'show_in_grid' => true, 'show_in_edit' => true],
                        'product_id' => ['display_name' => 'Product',    'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                        'contact_id' => ['display_name' => 'Contact',    'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                    ], 'foreign_keys' => [
                        'product_id' => ['reference_table' => 'products', 'reference_column' => 'id', 'display_column' => 'name'],
                        'contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => 'last_name'],
                    ], 'subtables' => []],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_crm_001', 'type' => 'stat_card', 'title' => 'Companies', 'table' => 'companies', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/apartment.png', 'color' => '#93c5fd', 'display_columns' => []],
                    ['id' => 'demo_crm_002', 'type' => 'stat_card', 'title' => 'Contacts', 'table' => 'contacts', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/person.png', 'color' => '#6ee7b7', 'display_columns' => []],
                    ['id' => 'demo_crm_004', 'type' => 'stat_card', 'title' => 'Pipeline Value', 'table' => 'deals', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/payments.png', 'color' => '#fcd34d', 'display_columns' => []],
                    ['id' => 'demo_crm_003', 'type' => 'bar_chart', 'title' => 'Deals by Stage', 'table' => 'deals', 'width' => 1, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'stage', 'conditions' => []], 'icon' => 'assets/icons/point_of_sale.png', 'color' => '#fcd34d', 'display_columns' => []],
                    ['id' => 'demo_crm_005', 'type' => 'pie_chart', 'title' => 'Activities Status', 'table' => 'activities', 'width' => 2, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'done', 'conditions' => []], 'icon' => 'assets/icons/calendar.png', 'color' => '#c4b5fd', 'display_columns' => []],
                    ['id' => 'demo_crm_007', 'type' => 'pie_chart', 'title' => 'Lead Sources', 'table' => 'leads', 'width' => 2, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'source', 'conditions' => []], 'icon' => 'assets/icons/account_tree.png', 'color' => '#c4b5fd', 'display_columns' => []],
                    ['id' => 'demo_crm_008', 'type' => 'bar_chart', 'title' => 'Quotes by Status', 'table' => 'quotes', 'width' => 1, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'status', 'conditions' => []], 'icon' => 'assets/icons/ballot.png', 'color' => '#c4b5fd', 'display_columns' => []],
                    ['id' => 'demo_crm_006', 'type' => 'stat_card', 'title' => 'Active Leads', 'table' => 'leads', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'status', 'op' => '!=', 'val' => 'Lost']]], 'icon' => 'assets/icons/person_text.png', 'color' => '#93c5fd', 'display_columns' => []],
                    ['id' => 'demo_crm_009', 'type' => 'stat_card', 'title' => 'Outstanding Invoices', 'table' => 'invoices', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'status', 'op' => '=', 'val' => 'Sent'], ['col' => 'status', 'op' => '=', 'val' => 'Overdue', 'logic' => 'OR']]], 'icon' => 'assets/icons/file_present.png', 'color' => '#fcd34d', 'display_columns' => []],
                    ['id' => 'demo_crm_010', 'type' => 'stat_card', 'title' => 'Active Assets', 'table' => 'assets', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'status', 'op' => '=', 'val' => 'Active']]], 'icon' => 'assets/icons/database.png', 'color' => '#d1d5db', 'display_columns' => []],
                ],
                'calendar_sources' => [
                    ['table' => 'activities', 'date_column' => 'scheduled_at', 'title_column' => 'type', 'color' => '#93c5fd', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=activities&id={id}', 'icon' => 'assets/icons/calendar.png', 'notified_users' => []],
                    ['table' => 'deals', 'date_column' => 'expected_close', 'title_column' => 'title', 'color' => '#fcd34d', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=deals&id={id}', 'icon' => 'assets/icons/point_of_sale.png', 'notified_users' => []],
                    ['table' => 'quotes', 'date_column' => 'valid_until', 'title_column' => 'quote_number', 'color' => '#c4b5fd', 'notify_before_days' => 7, 'url_template' => 'edit.php?table=quotes&id={id}', 'icon' => 'assets/icons/ballot.png', 'notified_users' => []],
                    ['table' => 'invoices', 'date_column' => 'due_date', 'title_column' => 'invoice_number', 'color' => '#f87171', 'notify_before_days' => 7, 'url_template' => 'edit.php?table=invoices&id={id}', 'icon' => 'assets/icons/file_present.png', 'notified_users' => []],
                    ['table' => 'assets', 'date_column' => 'warranty_end', 'title_column' => 'name', 'color' => '#d1d5db', 'notify_before_days' => 14, 'url_template' => 'edit.php?table=assets&id={id}', 'icon' => 'assets/icons/database.png', 'notified_users' => []],
                ],
                'workflows' => [
                    ['id' => 'wf_demo_crm_001', 'title' => 'New CRM Deal', 'icon' => 'assets/icons/apartment.png', 'description' => 'CRM: add company → contact → deal → activity.', 'steps' => [
                        ['title' => 'Add Company',  'table' => 'companies',  'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Add Contact',  'table' => 'contacts',   'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => true],
                        ['title' => 'Create Deal',  'table' => 'deals',      'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Log Activity', 'table' => 'activities', 'foreign_key' => 'deal_id',    'link_to_step' => 2, 'allow_multiple' => true],
                    ]],
                    ['id' => 'wf_demo_crm_002', 'title' => 'Convert Lead', 'icon' => 'assets/icons/person_text.png', 'description' => 'CRM: lead → company → contact → deal → quote.', 'steps' => [
                        ['title' => 'Capture Lead',  'table' => 'leads',     'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Add Company',   'table' => 'companies', 'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                        ['title' => 'Add Contact',   'table' => 'contacts',  'foreign_key' => 'company_id', 'link_to_step' => 1, 'allow_multiple' => false],
                        ['title' => 'Create Deal',   'table' => 'deals',     'foreign_key' => 'company_id', 'link_to_step' => 1, 'allow_multiple' => false],
                        ['title' => 'Send Quote',    'table' => 'quotes',    'foreign_key' => 'deal_id',    'link_to_step' => 3, 'allow_multiple' => true],
                    ]],
                ],
                'views' => [
                    'v_demo_crm_pipeline' => ['display_name' => 'CRM Pipeline', 'menu_name' => 'Pipeline Summary', 'icon' => 'assets/icons/point_of_sale.png', 'hidden' => false, 'description' => 'Deal count & value by stage.', 'columns' => [
                        'stage'       => ['display_name' => 'Stage'],
                        'deal_count'  => ['display_name' => 'Deals'],
                        'total_value' => ['display_name' => 'Total Value'],
                    ], 'drill_down' => ['enabled' => false]],
                    'v_demo_crm_leads_funnel' => ['display_name' => 'CRM Leads Funnel', 'menu_name' => 'Leads Funnel', 'icon' => 'assets/icons/account_tree.png', 'hidden' => false, 'description' => 'Lead count by qualification status.', 'columns' => [
                        'status'     => ['display_name' => 'Status'],
                        'lead_count' => ['display_name' => 'Leads'],
                    ], 'drill_down' => ['enabled' => false]],
                    'v_demo_crm_revenue' => ['display_name' => 'CRM Revenue', 'menu_name' => 'Revenue Summary', 'icon' => 'assets/icons/file_present.png', 'hidden' => false, 'description' => 'Invoice count & total by status.', 'columns' => [
                        'status'        => ['display_name' => 'Status'],
                        'invoice_count' => ['display_name' => 'Invoices'],
                        'total'         => ['display_name' => 'Total Amount'],
                    ], 'drill_down' => ['enabled' => false]],
                    'v_demo_crm_assets_by_category' => ['display_name' => 'Assets by Category', 'menu_name' => 'Assets Summary', 'icon' => 'assets/icons/database.png', 'hidden' => false, 'description' => 'Asset count & book value by category.', 'columns' => [
                        'category'    => ['display_name' => 'Category'],
                        'asset_count' => ['display_name' => 'Assets'],
                        'total_value' => ['display_name' => 'Total Value'],
                    ], 'drill_down' => ['enabled' => false]],
                ],
                'menu_items' => [
                    ['key' => 'companies', 'children' => [
                        ['key' => 'contacts'],
                        ['key' => 'leads'],
                    ]],
                    ['key' => 'deals', 'children' => [
                        ['key' => 'quotes'],
                        ['key' => 'invoices'],
                        ['key' => 'products'],
                    ]],
                    ['key' => 'assets'],
                    ['key' => 'activities'],
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
                        'id'         => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique warehouse identifier'],
                        'name'       => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Warehouse name or facility designation'],
                        'location'   => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Location', 'description' => 'Geographic location of warehouse (city, country)'],
                        'capacity'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Capacity', 'description' => 'Maximum storage capacity in units'],
                        'created_at' => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when warehouse record was created'],
                    ], 'subtables' => [
                        ['table' => 'stock', 'foreign_key' => 'warehouse_id', 'label' => 'Stock', 'columns_to_show' => ['product_id', 'quantity', 'min_quantity']],
                    ]],
                    'products' => ['display_name' => 'Products', 'schema' => 'spw_wms', 'icon' => 'assets/icons/package_2.png', 'columns' => [
                        'id'          => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique product identifier'],
                        'sku'         => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'SKU', 'description' => 'Stock Keeping Unit - unique product code'],
                        'name'        => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Product name'],
                        'description' => ['type' => 'text', 'display_name' => 'Description', 'description' => 'Detailed product description and specifications'],
                        'unit'        => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Unit', 'description' => 'Unit of measurement (pieces, kg, liters, etc.)'],
                        'category'    => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Category', 'description' => 'Product category or classification'],
                        'created_at'  => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when product record was created'],
                    ], 'subtables' => [
                        ['table' => 'stock',     'foreign_key' => 'product_id', 'label' => 'Stock',     'columns_to_show' => ['warehouse_id', 'quantity', 'min_quantity']],
                        ['table' => 'movements', 'foreign_key' => 'product_id', 'label' => 'Movements', 'columns_to_show' => ['warehouse_from', 'warehouse_to', 'quantity', 'type']],
                    ]],
                    'stock' => ['display_name' => 'Stock', 'schema' => 'spw_wms', 'icon' => 'assets/icons/inventory.png', 'columns' => [
                        'id'           => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique stock record identifier'],
                        'warehouse_id' => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Warehouse', 'description' => 'Warehouse where stock is stored'],
                        'product_id'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Product', 'description' => 'Product in this stock record'],
                        'quantity'     => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Quantity', 'description' => 'Current quantity in stock'],
                        'min_quantity' => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Min Quantity', 'description' => 'Minimum threshold quantity'],
                        'updated_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Updated At', 'description' => 'Date when stock quantity was last updated'],
                    ], 'foreign_keys' => [
                        'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                        'product_id'   => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                    ]],
                    'movements' => ['display_name' => 'Movements', 'schema' => 'spw_wms', 'icon' => 'assets/icons/arrow_split.png', 'columns' => [
                        'id'             => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique movement record identifier'],
                        'product_id'     => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Product', 'description' => 'Product being moved'],
                        'warehouse_from' => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'From', 'description' => 'Source warehouse (null for inbound)'],
                        'warehouse_to'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'To', 'description' => 'Destination warehouse (null for outbound)'],
                        'quantity'       => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Quantity', 'description' => 'Quantity moved'],
                        'type'           => ['type' => 'enum',   'show_in_grid' => true, 'options' => ['Inbound', 'Outbound', 'Transfer', 'Adjustment'], 'enum_colors' => ['Inbound' => '#6ee7b7', 'Outbound' => '#f87171', 'Transfer' => '#fcd34d', 'Adjustment' => '#c4b5fd'], 'display_name' => 'Type', 'description' => 'Movement type'],
                        'notes'          => ['type' => 'text', 'display_name' => 'Notes', 'description' => 'Additional movement notes'],
                        'moved_at'       => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Moved At', 'description' => 'Date when movement was recorded'],
                    ], 'foreign_keys' => [
                        'product_id'     => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                        'warehouse_from' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                        'warehouse_to'   => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                    ]],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_wms_001', 'type' => 'stat_card', 'title' => 'Warehouses', 'table' => 'warehouses', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/warehouse.png', 'color' => '#fcd34d', 'display_columns' => []],
                    ['id' => 'demo_wms_002', 'type' => 'stat_card', 'title' => 'Products', 'table' => 'products', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/package_2.png', 'color' => '#93c5fd', 'display_columns' => []],
                    ['id' => 'demo_wms_005', 'type' => 'stat_card', 'title' => 'Stock Entries', 'table' => 'stock', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/manage_history.png', 'color' => '#6ee7b7', 'display_columns' => []],
                    ['id' => 'demo_wms_004', 'type' => 'bar_chart', 'title' => 'Movements by Type', 'table' => 'movements', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'type'], 'icon' => 'assets/icons/arrow_split.png', 'color' => '#c4b5fd', 'display_columns' => []],
                    ['id' => 'demo_wms_006', 'type' => 'pie_chart', 'title' => 'Stock by Category', 'table' => 'products', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'category'], 'icon' => 'assets/icons/package_2.png', 'color' => '#93c5fd', 'display_columns' => []],
                ],
                'calendar_sources' => [
                    ['table' => 'movements', 'date_column' => 'moved_at', 'title_column' => 'type', 'color' => '#fcd34d', 'notify_before_days' => 0, 'url_template' => 'edit.php?table=movements&id={id}', 'icon' => 'assets/icons/arrow_split.png', 'notified_users' => []],
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
                        'low_stock'    => ['display_name' => 'Low', 'color_rules' => [['op' => '=', 'value' => 'true', 'color' => '#f87171']]],
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
                        'id'          => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique project identifier'],
                        'name'        => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Project name or title'],
                        'description' => ['type' => 'text', 'display_name' => 'Description', 'description' => 'Detailed project description'],
                        'status'      => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Active', 'On Hold', 'Completed', 'Cancelled'], 'enum_colors' => ['Active' => '#6ee7b7', 'On Hold' => '#fcd34d', 'Completed' => '#93c5fd', 'Cancelled' => '#f87171'], 'display_name' => 'Status', 'description' => 'Current project status'],
                        'priority'    => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#d1d5db', 'Medium' => '#fcd34d', 'High' => '#f87171', 'Critical' => '#c4b5fd'], 'display_name' => 'Priority', 'description' => 'Project priority level'],
                        'due_date'    => ['type' => 'date', 'show_in_grid' => true, 'display_name' => 'Due Date', 'description' => 'Projected project completion date'],
                        'created_at'  => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when project record was created'],
                    ], 'subtables' => [
                        ['table' => 'tasks', 'foreign_key' => 'project_id', 'label' => 'Tasks', 'columns_to_show' => ['title', 'status', 'priority', 'assigned_to', 'due_date']],
                    ]],
                    'tasks' => ['display_name' => 'Tasks', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/checklist_rtl.png', 'columns' => [
                        'id'          => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique task identifier'],
                        'project_id'  => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Project', 'description' => 'Project this task belongs to'],
                        'title'       => ['type' => 'text', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Title', 'description' => 'Task title or name'],
                        'description' => ['type' => 'text', 'display_name' => 'Description', 'description' => 'Detailed task description'],
                        'status'      => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Todo', 'In Progress', 'Review', 'Done', 'Blocked'], 'enum_colors' => ['Todo' => '#d1d5db', 'In Progress' => '#93c5fd', 'Review' => '#fcd34d', 'Done' => '#6ee7b7', 'Blocked' => '#f87171'], 'display_name' => 'Status', 'description' => 'Current task status'],
                        'priority'    => ['type' => 'enum', 'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#d1d5db', 'Medium' => '#fcd34d', 'High' => '#f87171', 'Critical' => '#c4b5fd'], 'display_name' => 'Priority', 'description' => 'Task priority level'],
                        'assigned_to' => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Assigned To', 'description' => 'Team member assigned to task'],
                        'due_date'    => ['type' => 'date', 'show_in_grid' => true, 'display_name' => 'Due Date', 'description' => 'Task completion deadline'],
                        'created_at'  => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when task record was created'],
                    ], 'foreign_keys' => [
                        'project_id' => ['reference_table' => 'projects', 'reference_column' => 'id', 'display_column' => 'name'],
                    ], 'subtables' => [
                        ['table' => 'time_logs', 'foreign_key' => 'task_id', 'label' => 'Time Logs', 'columns_to_show' => ['hours', 'description', 'logged_at']],
                    ]],
                    'time_logs' => ['display_name' => 'Time Logs', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/watch_screentime.png', 'columns' => [
                        'id'          => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique time log record identifier'],
                        'task_id'     => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Task', 'description' => 'Task this time log is for'],
                        'hours'       => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Hours', 'description' => 'Hours spent on task'],
                        'description' => ['type' => 'text', 'show_in_grid' => true, 'display_name' => 'Description', 'description' => 'Work description and notes'],
                        'logged_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Logged At', 'description' => 'Date when time was logged'],
                    ], 'foreign_keys' => [
                        'task_id' => ['reference_table' => 'tasks', 'reference_column' => 'id', 'display_column' => 'title'],
                    ]],
                ],
                'dashboard_widgets' => [
                    ['id' => 'demo_tasks_001', 'type' => 'stat_card', 'title' => 'Projects', 'table' => 'projects', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/account_tree.png', 'color' => '#6ee7b7', 'display_columns' => []],
                    ['id' => 'demo_tasks_002', 'type' => 'stat_card', 'title' => 'Open Tasks', 'table' => 'tasks', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/checklist_rtl.png', 'color' => '#93c5fd', 'display_columns' => []],
                    ['id' => 'demo_tasks_004', 'type' => 'stat_card', 'title' => 'Total Hours', 'table' => 'time_logs', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/watch_screentime.png', 'color' => '#c4b5fd', 'display_columns' => []],
                    ['id' => 'demo_tasks_003', 'type' => 'pie_chart', 'title' => 'Task Status', 'table' => 'tasks', 'width' => 3, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'status'], 'icon' => 'assets/icons/checklist_rtl.png', 'color' => '#fcd34d', 'display_columns' => []],
                    ['id' => 'demo_tasks_005', 'type' => 'list', 'title' => 'Overdue Tasks', 'table' => 'tasks', 'width' => 3, 'height' => 2, 'query' => [], 'icon' => 'assets/icons/fact_check.png', 'color' => '#f87171', 'display_columns' => ['title', 'project_id', 'assigned_to', 'due_date']],
                ],
                'calendar_sources' => [
                    ['table' => 'projects', 'date_column' => 'due_date', 'title_column' => 'name', 'color' => '#6ee7b7', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=projects&id={id}', 'icon' => 'assets/icons/account_tree.png', 'notified_users' => []],
                    ['table' => 'tasks', 'date_column' => 'due_date', 'title_column' => 'title', 'color' => '#93c5fd', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=tasks&id={id}', 'icon' => 'assets/icons/checklist_rtl.png', 'notified_users' => []],
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
