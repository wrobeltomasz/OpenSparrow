<?php

declare(strict_types=1);

function demo_def_wms($conn): array
{
    return [
        'pg_schema'  => 'spw_wms',
        'view_names' => [
            'v_demo_wms_stock',
            'v_demo_wms_low_stock',
            'v_demo_wms_po_status',
            'v_demo_wms_expiring_soon',
            'v_demo_wms_inventory_value',
        ],
        'ddl' => [
            'CREATE SCHEMA IF NOT EXISTS spw_wms',
            "CREATE TABLE IF NOT EXISTS spw_wms.warehouses (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, location VARCHAR(255), capacity INTEGER, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.locations (id SERIAL PRIMARY KEY, warehouse_id INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE CASCADE, zone VARCHAR(50) NOT NULL, aisle VARCHAR(20), bin VARCHAR(20), type VARCHAR(50) DEFAULT 'Storage', is_active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.suppliers (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, country VARCHAR(100), contact_email VARCHAR(255), phone VARCHAR(50), lead_time_days INTEGER DEFAULT 7, active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.products (id SERIAL PRIMARY KEY, sku VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, description TEXT, unit VARCHAR(50), category VARCHAR(100), weight NUMERIC(8,3), unit_price NUMERIC(12,2) DEFAULT 0, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.batches (id SERIAL PRIMARY KEY, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE CASCADE, supplier_id INTEGER REFERENCES spw_wms.suppliers(id) ON DELETE SET NULL, batch_number VARCHAR(100) NOT NULL, lot_number VARCHAR(100), manufacture_date DATE, expiry_date DATE, status VARCHAR(50) DEFAULT 'Active', created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.stock (id SERIAL PRIMARY KEY, warehouse_id INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE CASCADE, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE CASCADE, location_id INTEGER REFERENCES spw_wms.locations(id) ON DELETE SET NULL, batch_id INTEGER REFERENCES spw_wms.batches(id) ON DELETE SET NULL, quantity INTEGER DEFAULT 0, min_quantity INTEGER DEFAULT 0, updated_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.movements (id SERIAL PRIMARY KEY, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE SET NULL, warehouse_from INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, warehouse_to INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, quantity INTEGER NOT NULL, type VARCHAR(50) DEFAULT 'Transfer', notes TEXT, moved_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.customers (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, country VARCHAR(100), email VARCHAR(255), phone VARCHAR(50), created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.purchase_orders (id SERIAL PRIMARY KEY, supplier_id INTEGER REFERENCES spw_wms.suppliers(id) ON DELETE SET NULL, po_number VARCHAR(50) NOT NULL UNIQUE, status VARCHAR(50) DEFAULT 'Draft', warehouse_id INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, expected_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.po_items (id SERIAL PRIMARY KEY, po_id INTEGER REFERENCES spw_wms.purchase_orders(id) ON DELETE CASCADE, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE SET NULL, ordered_qty INTEGER NOT NULL, received_qty INTEGER DEFAULT 0, unit_price NUMERIC(12,2) DEFAULT 0)",
            "CREATE TABLE IF NOT EXISTS spw_wms.orders (id SERIAL PRIMARY KEY, customer_id INTEGER REFERENCES spw_wms.customers(id) ON DELETE SET NULL, order_number VARCHAR(50) NOT NULL UNIQUE, status VARCHAR(50) DEFAULT 'New', warehouse_id INTEGER REFERENCES spw_wms.warehouses(id) ON DELETE SET NULL, required_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_wms.order_items (id SERIAL PRIMARY KEY, order_id INTEGER REFERENCES spw_wms.orders(id) ON DELETE CASCADE, product_id INTEGER REFERENCES spw_wms.products(id) ON DELETE SET NULL, ordered_qty INTEGER NOT NULL, picked_qty INTEGER DEFAULT 0)",
            "CREATE TABLE IF NOT EXISTS spw_wms.shipments (id SERIAL PRIMARY KEY, order_id INTEGER REFERENCES spw_wms.orders(id) ON DELETE CASCADE, carrier VARCHAR(100), tracking_number VARCHAR(100), dispatched_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE OR REPLACE VIEW spw_wms.v_demo_wms_stock AS SELECT p.sku, p.name AS product, p.category, w.name AS warehouse, l.zone, s.quantity, s.min_quantity, (s.quantity < s.min_quantity) AS low_stock FROM spw_wms.stock s JOIN spw_wms.products p ON p.id = s.product_id JOIN spw_wms.warehouses w ON w.id = s.warehouse_id LEFT JOIN spw_wms.locations l ON l.id = s.location_id",
            "CREATE OR REPLACE VIEW spw_wms.v_demo_wms_low_stock AS SELECT p.sku, p.name AS product, w.name AS warehouse, s.quantity, s.min_quantity, (s.min_quantity - s.quantity) AS shortage FROM spw_wms.stock s JOIN spw_wms.products p ON p.id = s.product_id JOIN spw_wms.warehouses w ON w.id = s.warehouse_id WHERE s.quantity < s.min_quantity ORDER BY shortage DESC",
            "CREATE OR REPLACE VIEW spw_wms.v_demo_wms_po_status AS SELECT po.po_number, po.status, s.name AS supplier, w.name AS warehouse, po.expected_date, COUNT(poi.id) AS line_count, COALESCE(SUM(poi.ordered_qty * poi.unit_price), 0) AS total_value FROM spw_wms.purchase_orders po LEFT JOIN spw_wms.suppliers s ON s.id = po.supplier_id LEFT JOIN spw_wms.warehouses w ON w.id = po.warehouse_id LEFT JOIN spw_wms.po_items poi ON poi.po_id = po.id GROUP BY po.id, po.po_number, po.status, s.name, w.name, po.expected_date ORDER BY po.expected_date",
            "CREATE OR REPLACE VIEW spw_wms.v_demo_wms_expiring_soon AS SELECT b.batch_number, p.sku, p.name AS product, b.expiry_date, b.status AS batch_status, COALESCE(SUM(s.quantity), 0) AS qty_on_hand, (b.expiry_date - CURRENT_DATE) AS days_to_expiry FROM spw_wms.batches b JOIN spw_wms.products p ON p.id = b.product_id LEFT JOIN spw_wms.stock s ON s.batch_id = b.id WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= CURRENT_DATE + INTERVAL '90 days' GROUP BY b.id, b.batch_number, p.sku, p.name, b.expiry_date, b.status ORDER BY b.expiry_date",
            "CREATE OR REPLACE VIEW spw_wms.v_demo_wms_inventory_value AS SELECT w.name AS warehouse, p.category, p.sku, p.name AS product, s.quantity, p.unit_price, (s.quantity * p.unit_price) AS stock_value FROM spw_wms.stock s JOIN spw_wms.products p ON p.id = s.product_id JOIN spw_wms.warehouses w ON w.id = s.warehouse_id WHERE p.unit_price > 0 ORDER BY w.name, p.category, stock_value DESC",
        ],
        'seed_data' => [
            // warehouses (IDs 1-5)
            "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('Central Hub', 'Chicago, USA', 50000)",
            "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('West Coast DC', 'Los Angeles, USA', 35000)",
            "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('East Coast Distribution', 'New York, USA', 45000)",
            "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('European Facility', 'Amsterdam, Netherlands', 30000)",
            "INSERT INTO spw_wms.warehouses (name, location, capacity) VALUES ('Asia Pacific', 'Singapore', 40000)",
            // locations (W1: IDs 1-5, W2: 6-7, W3: 8-9, W4: 10, W5: 11)
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (1, 'Zone-A', 'A1', 'B01', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (1, 'Zone-A', 'A1', 'B02', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (1, 'Zone-B', 'B3', 'B01', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (1, 'Receiving', NULL, NULL, 'Receiving')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (1, 'Dispatch', NULL, NULL, 'Dispatch')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (2, 'Zone-A', 'A1', 'B01', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (2, 'Receiving', NULL, NULL, 'Receiving')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (3, 'Zone-A', 'A1', 'B01', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (3, 'Receiving', NULL, NULL, 'Receiving')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (4, 'Zone-A', 'A1', 'B01', 'Storage')",
            "INSERT INTO spw_wms.locations (warehouse_id, zone, aisle, bin, type) VALUES (5, 'Zone-A', 'A1', 'B01', 'Storage')",
            // suppliers (IDs 1-4)
            "INSERT INTO spw_wms.suppliers (name, country, contact_email, phone, lead_time_days) VALUES ('TechSupply Co', 'China', 'supply@techsupply.cn', '+86-10-555-0101', 14)",
            "INSERT INTO spw_wms.suppliers (name, country, contact_email, phone, lead_time_days) VALUES ('EuroGadgets GmbH', 'Germany', 'orders@eurogadgets.de', '+49-30-555-0202', 7)",
            "INSERT INTO spw_wms.suppliers (name, country, contact_email, phone, lead_time_days) VALUES ('Pacific Distributors', 'Japan', 'pd@pacdist.jp', '+81-3-555-0303', 21)",
            "INSERT INTO spw_wms.suppliers (name, country, contact_email, phone, lead_time_days) VALUES ('GlobalSource Ltd', 'Singapore', 'info@globalsource.sg', '+65-6555-0404', 5)",
            // products (IDs 1-6) — weight (kg) and unit_price added
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-001', 'Wireless Mouse', 'Ergonomic 2.4GHz wireless mouse', 'Unit', 'Electronics', 0.095, 18.50)",
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-002', 'USB-C Cable 2m', '2-meter high-speed USB-C cable', 'Unit', 'Accessories', 0.080, 5.90)",
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-003', 'Laptop Stand', 'Adjustable aluminum laptop stand', 'Unit', 'Office', 1.200, 45.00)",
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-004', 'Mechanical Keyboard', 'Mechanical RGB gaming keyboard', 'Unit', 'Electronics', 0.850, 89.00)",
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-005', 'Monitor 27\" 4K', '27-inch 4K UHD monitor', 'Unit', 'Electronics', 5.200, 350.00)",
            "INSERT INTO spw_wms.products (sku, name, description, unit, category, weight, unit_price) VALUES ('PROD-006', 'LED Desk Lamp', 'LED desk lamp with USB charging', 'Unit', 'Office', 0.680, 32.00)",
            // batches (IDs 1-8; ID 7 & 8 expiring within 90 days)
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (1, 1, 'BATCH-2024-001', 'LOT-A1', '2024-01-15', '2026-07-15', 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (2, 1, 'BATCH-2024-002', 'LOT-B2', '2024-03-01', '2026-09-01', 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (3, 2, 'BATCH-2024-003', 'LOT-C3', '2024-06-01', NULL, 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (4, 3, 'BATCH-2025-001', 'LOT-D1', '2025-01-10', NULL, 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (5, 2, 'BATCH-2025-002', 'LOT-E2', '2025-02-20', NULL, 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (6, 4, 'BATCH-2025-003', 'LOT-F3', '2025-03-15', NULL, 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (1, 3, 'BATCH-2026-001', 'LOT-A2', '2026-01-01', CURRENT_DATE + INTERVAL '29 days', 'Active')",
            "INSERT INTO spw_wms.batches (product_id, supplier_id, batch_number, lot_number, manufacture_date, expiry_date, status) VALUES (2, 1, 'BATCH-2026-002', 'LOT-B3', '2026-02-01', CURRENT_DATE + INTERVAL '75 days', 'Active')",
            // stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity)
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (1, 1, 1, 1, 450, 100)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (1, 2, 1, 2, 1200, 200)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (1, 3, 2, 3, 85, 50)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (2, 1, 6, 1, 320, 100)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (2, 4, 6, 4, 40, 80)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (3, 5, 8, 5, 55, 40)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (3, 6, 8, 6, 200, 100)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (4, 2, 10, 2, 890, 200)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (5, 1, 11, 7, 520, 100)",
            "INSERT INTO spw_wms.stock (warehouse_id, product_id, location_id, batch_id, quantity, min_quantity) VALUES (1, 1, 3, 7, 150, 100)",
            // movements
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (1, 1, 2, 100, 'Transfer', 'Regular stock replenishment', NOW() - INTERVAL '3 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (2, 1, 3, 300, 'Transfer', 'Support East region demand', NOW() - INTERVAL '1 day')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (3, NULL, 1, 200, 'Inbound', 'Received from EuroGadgets — PO-2026-002', NOW() - INTERVAL '7 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (4, 2, NULL, 150, 'Outbound', 'Shipped to customer Acme Electronics', NOW() - INTERVAL '4 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (5, 3, 4, 20, 'Transfer', 'EU balance adjustment', NOW() - INTERVAL '2 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (1, NULL, 5, 520, 'Inbound', 'APAC initial stock — PO-2026-003', NOW() - INTERVAL '10 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (6, 4, NULL, 80, 'Outbound', 'Delivery to EuroBuy SRL', NOW() - INTERVAL '2 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (2, 4, 1, 100, 'Transfer', 'Consolidation from Amsterdam', NOW() - INTERVAL '5 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (4, NULL, 1, 200, 'Inbound', 'Emergency restock — PO-2026-001', NOW() - INTERVAL '6 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (3, 1, NULL, 30, 'Outbound', 'Sample shipment to Nordic Retail', NOW() - INTERVAL '1 day')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (1, 1, 3, 50, 'Transfer', 'NYC pre-season stock build', NOW() - INTERVAL '8 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (5, NULL, 3, 10, 'Inbound', 'Monitor restock received', NOW() - INTERVAL '15 days')",
            "INSERT INTO spw_wms.movements (product_id, warehouse_from, warehouse_to, quantity, type, notes, moved_at) VALUES (4, 1, 2, 60, 'Adjustment', 'Cycle count correction — Zone A', NOW() - INTERVAL '12 days')",
            // customers (IDs 1-5)
            "INSERT INTO spw_wms.customers (name, country, email, phone) VALUES ('Acme Electronics', 'Germany', 'procurement@acme-electronics.de', '+49-89-555-0101')",
            "INSERT INTO spw_wms.customers (name, country, email, phone) VALUES ('Nordic Retail AB', 'Sweden', 'orders@nordicretail.se', '+46-8-555-0202')",
            "INSERT INTO spw_wms.customers (name, country, email, phone) VALUES ('Pacific Trade Co', 'Australia', 'supply@pacifictrade.com.au', '+61-2-5550-0303')",
            "INSERT INTO spw_wms.customers (name, country, email, phone) VALUES ('Central Depot LLC', 'USA', 'buy@centraldepot.com', '+1-312-555-0404')",
            "INSERT INTO spw_wms.customers (name, country, email, phone) VALUES ('EuroBuy SRL', 'Italy', 'acquisti@eurobuy.it', '+39-02-5550-0505')",
            // purchase_orders (IDs 1-4)
            "INSERT INTO spw_wms.purchase_orders (supplier_id, po_number, status, warehouse_id, expected_date, notes) VALUES (1, 'PO-2026-001', 'Ordered', 1, CURRENT_DATE + INTERVAL '7 days', 'Q2 mouse and keyboard replenishment')",
            "INSERT INTO spw_wms.purchase_orders (supplier_id, po_number, status, warehouse_id, expected_date, notes) VALUES (2, 'PO-2026-002', 'Partial', 3, CURRENT_DATE + INTERVAL '3 days', 'Stands and monitor restock — 2nd delivery pending')",
            "INSERT INTO spw_wms.purchase_orders (supplier_id, po_number, status, warehouse_id, expected_date, notes) VALUES (3, 'PO-2026-003', 'Draft', 2, CURRENT_DATE + INTERVAL '14 days', 'West Coast keyboard stock')",
            "INSERT INTO spw_wms.purchase_orders (supplier_id, po_number, status, warehouse_id, expected_date, notes) VALUES (4, 'PO-2026-004', 'Received', 4, CURRENT_DATE - INTERVAL '5 days', 'Lamp restock — fully received')",
            // po_items
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (1, 1, 1000, 0, 12.00)",
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (1, 4, 300, 0, 55.00)",
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (2, 3, 200, 100, 28.00)",
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (2, 5, 20, 0, 220.00)",
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (3, 4, 300, 0, 55.00)",
            "INSERT INTO spw_wms.po_items (po_id, product_id, ordered_qty, received_qty, unit_price) VALUES (4, 6, 150, 150, 20.00)",
            // orders (IDs 1-5)
            "INSERT INTO spw_wms.orders (customer_id, order_number, status, warehouse_id, required_date, notes) VALUES (1, 'ORD-2026-001', 'Picking', 1, CURRENT_DATE + INTERVAL '5 days', 'Priority order — Acme Q2 delivery')",
            "INSERT INTO spw_wms.orders (customer_id, order_number, status, warehouse_id, required_date, notes) VALUES (2, 'ORD-2026-002', 'New', 2, CURRENT_DATE + INTERVAL '10 days', 'Nordic Retail spring collection')",
            "INSERT INTO spw_wms.orders (customer_id, order_number, status, warehouse_id, required_date, notes) VALUES (3, 'ORD-2026-003', 'Shipped', 3, CURRENT_DATE - INTERVAL '2 days', 'Pacific Trade monthly replenishment')",
            "INSERT INTO spw_wms.orders (customer_id, order_number, status, warehouse_id, required_date, notes) VALUES (4, 'ORD-2026-004', 'Delivered', 1, CURRENT_DATE - INTERVAL '10 days', 'Central Depot bulk order — completed')",
            "INSERT INTO spw_wms.orders (customer_id, order_number, status, warehouse_id, required_date, notes) VALUES (5, 'ORD-2026-005', 'New', 4, CURRENT_DATE + INTERVAL '15 days', 'EuroBuy SRL standing order')",
            // order_items
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (1, 1, 50, 30)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (1, 2, 100, 100)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (2, 4, 20, 0)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (2, 6, 15, 0)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (3, 5, 5, 5)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (3, 3, 10, 10)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (4, 1, 100, 100)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (4, 3, 10, 10)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (5, 6, 25, 0)",
            "INSERT INTO spw_wms.order_items (order_id, product_id, ordered_qty, picked_qty) VALUES (5, 2, 50, 0)",
            // shipments
            "INSERT INTO spw_wms.shipments (order_id, carrier, tracking_number, dispatched_at, delivered_at) VALUES (3, 'DHL Express', '1Z999AA1012345678', NOW() - INTERVAL '3 days', NULL)",
            "INSERT INTO spw_wms.shipments (order_id, carrier, tracking_number, dispatched_at, delivered_at) VALUES (4, 'FedEx International', '7489348723456', NOW() - INTERVAL '12 days', NOW() - INTERVAL '9 days')",
        ],
        'schema_tables' => [
            'warehouses' => ['display_name' => 'Warehouses', 'schema' => 'spw_wms', 'icon' => 'assets/icons/warehouse.png', 'columns' => [
                'id'         => ['type' => 'number',    'display_name' => 'ID',       'description' => 'Unique warehouse identifier'],
                'name'       => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name',     'description' => 'Warehouse name or facility designation'],
                'location'   => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Location', 'description' => 'Geographic location (city, country)'],
                'capacity'   => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Capacity', 'description' => 'Maximum storage capacity in units'],
                'created_at' => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Record creation date'],
            ], 'subtables' => [
                ['table' => 'locations', 'foreign_key' => 'warehouse_id', 'label' => 'Locations', 'columns_to_show' => ['zone', 'aisle', 'bin', 'type', 'is_active']],
                ['table' => 'stock',     'foreign_key' => 'warehouse_id', 'label' => 'Stock',     'columns_to_show' => ['product_id', 'quantity', 'min_quantity']],
            ]],

            'locations' => ['display_name' => 'Locations', 'schema' => 'spw_wms', 'icon' => 'assets/icons/location_away.png', 'columns' => [
                'id'           => ['type' => 'number',  'display_name' => 'ID',        'description' => 'Unique location identifier'],
                'warehouse_id' => ['type' => 'number',  'show_in_grid' => true, 'display_name' => 'Warehouse', 'description' => 'Warehouse this location belongs to'],
                'zone'         => ['type' => 'text',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Zone',    'description' => 'Zone name within the warehouse'],
                'aisle'        => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Aisle',   'description' => 'Aisle identifier'],
                'bin'          => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Bin',     'description' => 'Bin or shelf identifier'],
                'type'         => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Storage', 'Receiving', 'Dispatch', 'Staging', 'Returns'], 'enum_colors' => ['Storage' => '#6ee7b7', 'Receiving' => '#93c5fd', 'Dispatch' => '#fcd34d', 'Staging' => '#c4b5fd', 'Returns' => '#f87171'], 'display_name' => 'Type', 'description' => 'Location type / function'],
                'is_active'    => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#6ee7b7', 'false' => '#f87171'], 'display_name' => 'Active', 'description' => 'Whether location is in service'],
                'created_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Record creation date'],
            ], 'foreign_keys' => [
                'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
            ]],

            'suppliers' => ['display_name' => 'Suppliers', 'schema' => 'spw_wms', 'icon' => 'assets/icons/local_shipping.png', 'columns' => [
                'id'             => ['type' => 'number',  'display_name' => 'ID',          'description' => 'Unique supplier identifier'],
                'name'           => ['type' => 'text',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name',          'description' => 'Supplier company name'],
                'country'        => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Country',       'description' => 'Country of origin'],
                'contact_email'  => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Email',         'description' => 'Primary ordering email'],
                'phone'          => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Phone',         'description' => 'Contact phone number'],
                'lead_time_days' => ['type' => 'number',  'show_in_grid' => true, 'display_name' => 'Lead Time (d)', 'description' => 'Average delivery lead time in days'],
                'active'         => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#6ee7b7', 'false' => '#f87171'], 'display_name' => 'Active', 'description' => 'Whether supplier is approved and active'],
                'created_at'     => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Record creation date'],
            ], 'subtables' => [
                ['table' => 'purchase_orders', 'foreign_key' => 'supplier_id', 'label' => 'Purchase Orders', 'columns_to_show' => ['po_number', 'status', 'expected_date']],
            ]],

            'products' => ['display_name' => 'Products', 'schema' => 'spw_wms', 'icon' => 'assets/icons/package_2.png', 'columns' => [
                'id'          => ['type' => 'number',    'display_name' => 'ID',          'description' => 'Unique product identifier'],
                'sku'         => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'SKU',         'description' => 'Stock Keeping Unit — unique product code'],
                'name'        => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name',        'description' => 'Product name'],
                'description' => ['type' => 'text',      'display_name' => 'Description', 'description' => 'Product description and specifications'],
                'unit'        => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Unit',        'description' => 'Unit of measure (Unit, kg, litre, etc.)'],
                'category'    => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Category',    'description' => 'Product category'],
                'weight'      => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Weight (kg)', 'description' => 'Gross weight per unit in kilograms'],
                'unit_price'  => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Unit Price',  'description' => 'Standard purchase/transfer price per unit'],
                'created_at'  => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At',  'description' => 'Record creation date'],
            ], 'subtables' => [
                ['table' => 'batches',   'foreign_key' => 'product_id', 'label' => 'Batches',   'columns_to_show' => ['batch_number', 'lot_number', 'expiry_date', 'status']],
                ['table' => 'stock',     'foreign_key' => 'product_id', 'label' => 'Stock',     'columns_to_show' => ['warehouse_id', 'quantity', 'min_quantity']],
                ['table' => 'movements', 'foreign_key' => 'product_id', 'label' => 'Movements', 'columns_to_show' => ['warehouse_from', 'warehouse_to', 'quantity', 'type']],
            ]],

            'batches' => ['display_name' => 'Batches / Lots', 'schema' => 'spw_wms', 'icon' => 'assets/icons/inventory.png', 'columns' => [
                'id'               => ['type' => 'number',    'display_name' => 'ID',               'description' => 'Unique batch identifier'],
                'product_id'       => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Product',          'description' => 'Product this batch belongs to'],
                'supplier_id'      => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Supplier',         'description' => 'Supplier who produced/delivered this batch'],
                'batch_number'     => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Batch #',          'description' => 'Internal batch tracking number'],
                'lot_number'       => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Lot #',            'description' => 'Supplier lot number for traceability'],
                'manufacture_date' => ['type' => 'date',      'show_in_grid' => false, 'display_name' => 'Manufactured',    'description' => 'Date of manufacture'],
                'expiry_date'      => ['type' => 'date',      'show_in_grid' => true,  'display_name' => 'Expiry Date',     'description' => 'Expiration or best-before date (NULL = no expiry)'],
                'status'           => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Active', 'Quarantine', 'Expired', 'Recalled'], 'enum_colors' => ['Active' => '#6ee7b7', 'Quarantine' => '#fcd34d', 'Expired' => '#f87171', 'Recalled' => '#c4b5fd'], 'display_name' => 'Status', 'description' => 'Batch disposition status'],
                'created_at'       => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Record creation date'],
            ], 'foreign_keys' => [
                'product_id'  => ['reference_table' => 'products',  'reference_column' => 'id', 'display_column' => 'sku'],
                'supplier_id' => ['reference_table' => 'suppliers', 'reference_column' => 'id', 'display_column' => 'name'],
            ]],

            'stock' => ['display_name' => 'Stock', 'schema' => 'spw_wms', 'icon' => 'assets/icons/inventory.png', 'columns' => [
                'id'           => ['type' => 'number',    'display_name' => 'ID',          'description' => 'Unique stock record identifier'],
                'warehouse_id' => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Warehouse',    'description' => 'Warehouse holding this stock'],
                'product_id'   => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Product',      'description' => 'Product stored'],
                'location_id'  => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Location',     'description' => 'Specific bin/zone within the warehouse'],
                'batch_id'     => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Batch',        'description' => 'Batch or lot this stock belongs to'],
                'quantity'     => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Quantity',     'description' => 'Current quantity on hand'],
                'min_quantity' => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Min Qty',      'description' => 'Reorder threshold — alert fires below this'],
                'updated_at'   => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Updated At', 'description' => 'Last stock update timestamp'],
            ], 'foreign_keys' => [
                'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                'product_id'   => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                'location_id'  => ['reference_table' => 'locations',  'reference_column' => 'id', 'display_column' => 'zone'],
                'batch_id'     => ['reference_table' => 'batches',    'reference_column' => 'id', 'display_column' => 'batch_number'],
            ]],

            'movements' => ['display_name' => 'Movements', 'schema' => 'spw_wms', 'icon' => 'assets/icons/arrow_split.png', 'columns' => [
                'id'             => ['type' => 'number',    'display_name' => 'ID',       'description' => 'Unique movement record identifier'],
                'product_id'     => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Product',  'description' => 'Product being moved'],
                'warehouse_from' => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'From',     'description' => 'Source warehouse (NULL for inbound from supplier)'],
                'warehouse_to'   => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'To',       'description' => 'Destination warehouse (NULL for outbound to customer)'],
                'quantity'       => ['type' => 'number',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Quantity',  'description' => 'Units moved'],
                'type'           => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Inbound', 'Outbound', 'Transfer', 'Adjustment'], 'enum_colors' => ['Inbound' => '#6ee7b7', 'Outbound' => '#f87171', 'Transfer' => '#fcd34d', 'Adjustment' => '#c4b5fd'], 'display_name' => 'Type', 'description' => 'Movement type'],
                'notes'          => ['type' => 'text',      'display_name' => 'Notes',    'description' => 'Reference (PO number, order number, reason)'],
                'moved_at'       => ['type' => 'timestamp', 'show_in_grid' => true, 'display_name' => 'Moved At', 'description' => 'Date/time of the movement'],
            ], 'foreign_keys' => [
                'product_id'     => ['reference_table' => 'products',   'reference_column' => 'id', 'display_column' => 'sku'],
                'warehouse_from' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
                'warehouse_to'   => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
            ]],

            'customers' => ['display_name' => 'Customers', 'schema' => 'spw_wms', 'icon' => 'assets/icons/person.png', 'columns' => [
                'id'         => ['type' => 'number',    'display_name' => 'ID',      'description' => 'Unique customer identifier'],
                'name'       => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name',    'description' => 'Customer company or individual name'],
                'country'    => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Country', 'description' => 'Customer country'],
                'email'      => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Email',   'description' => 'Ordering contact email'],
                'phone'      => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Phone',   'description' => 'Contact phone'],
                'created_at' => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Record creation date'],
            ], 'subtables' => [
                ['table' => 'orders', 'foreign_key' => 'customer_id', 'label' => 'Orders', 'columns_to_show' => ['order_number', 'status', 'required_date']],
            ]],

            'purchase_orders' => ['display_name' => 'Purchase Orders', 'schema' => 'spw_wms', 'icon' => 'assets/icons/ballot.png', 'columns' => [
                'id'            => ['type' => 'number',    'display_name' => 'ID',            'description' => 'Unique PO identifier'],
                'supplier_id'   => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Supplier',      'description' => 'Supplier for this purchase order'],
                'po_number'     => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'PO Number',     'description' => 'Human-readable purchase order number'],
                'status'        => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Draft', 'Ordered', 'Partial', 'Received', 'Cancelled'], 'enum_colors' => ['Draft' => '#d1d5db', 'Ordered' => '#93c5fd', 'Partial' => '#fcd34d', 'Received' => '#6ee7b7', 'Cancelled' => '#f87171'], 'display_name' => 'Status', 'description' => 'PO lifecycle status'],
                'warehouse_id'  => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Destination',   'description' => 'Warehouse where goods will be received'],
                'expected_date' => ['type' => 'date',      'show_in_grid' => true, 'display_name' => 'Expected',      'description' => 'Expected delivery date'],
                'notes'         => ['type' => 'text',      'show_in_grid' => false, 'display_name' => 'Notes',        'description' => 'Internal notes or delivery instructions'],
                'created_at'    => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At',   'description' => 'Record creation date'],
            ], 'foreign_keys' => [
                'supplier_id'  => ['reference_table' => 'suppliers',  'reference_column' => 'id', 'display_column' => 'name'],
                'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
            ], 'subtables' => [
                ['table' => 'po_items', 'foreign_key' => 'po_id', 'label' => 'PO Lines', 'columns_to_show' => ['product_id', 'ordered_qty', 'received_qty', 'unit_price']],
            ]],

            'po_items' => ['display_name' => 'PO Lines', 'schema' => 'spw_wms', 'icon' => 'assets/icons/order_approve.png', 'columns' => [
                'id'           => ['type' => 'number', 'display_name' => 'ID',           'description' => 'Unique PO line identifier'],
                'po_id'        => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Purchase Order', 'description' => 'Parent purchase order'],
                'product_id'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Product',        'description' => 'Product ordered'],
                'ordered_qty'  => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Ordered Qty',   'description' => 'Quantity ordered from supplier'],
                'received_qty' => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Received Qty',  'description' => 'Quantity actually received so far'],
                'unit_price'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Unit Price',    'description' => 'Agreed purchase price per unit'],
            ], 'foreign_keys' => [
                'po_id'      => ['reference_table' => 'purchase_orders', 'reference_column' => 'id', 'display_column' => 'po_number'],
                'product_id' => ['reference_table' => 'products',        'reference_column' => 'id', 'display_column' => 'sku'],
            ]],

            'orders' => ['display_name' => 'Customer Orders', 'schema' => 'spw_wms', 'icon' => 'assets/icons/shopping_cart.png', 'columns' => [
                'id'            => ['type' => 'number',    'display_name' => 'ID',           'description' => 'Unique order identifier'],
                'customer_id'   => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Customer',     'description' => 'Customer who placed the order'],
                'order_number'  => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Order #',      'description' => 'Human-readable order number'],
                'status'        => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['New', 'Picking', 'Packed', 'Shipped', 'Delivered', 'Cancelled'], 'enum_colors' => ['New' => '#d1d5db', 'Picking' => '#93c5fd', 'Packed' => '#fcd34d', 'Shipped' => '#c4b5fd', 'Delivered' => '#6ee7b7', 'Cancelled' => '#f87171'], 'display_name' => 'Status', 'description' => 'Order fulfillment status'],
                'warehouse_id'  => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Warehouse',    'description' => 'Dispatch warehouse'],
                'required_date' => ['type' => 'date',      'show_in_grid' => true, 'display_name' => 'Required By',  'description' => 'Customer required delivery date'],
                'notes'         => ['type' => 'text',      'show_in_grid' => false, 'display_name' => 'Notes',       'description' => 'Special delivery instructions'],
                'created_at'    => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At',  'description' => 'Record creation date'],
            ], 'foreign_keys' => [
                'customer_id'  => ['reference_table' => 'customers',  'reference_column' => 'id', 'display_column' => 'name'],
                'warehouse_id' => ['reference_table' => 'warehouses', 'reference_column' => 'id', 'display_column' => 'name'],
            ], 'subtables' => [
                ['table' => 'order_items', 'foreign_key' => 'order_id', 'label' => 'Order Lines', 'columns_to_show' => ['product_id', 'ordered_qty', 'picked_qty']],
                ['table' => 'shipments',   'foreign_key' => 'order_id', 'label' => 'Shipments',   'columns_to_show' => ['carrier', 'tracking_number', 'dispatched_at', 'delivered_at']],
            ]],

            'order_items' => ['display_name' => 'Order Lines', 'schema' => 'spw_wms', 'icon' => 'assets/icons/fact_check.png', 'columns' => [
                'id'          => ['type' => 'number', 'display_name' => 'ID',          'description' => 'Unique order line identifier'],
                'order_id'    => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Order',        'description' => 'Parent customer order'],
                'product_id'  => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Product',      'description' => 'Product ordered by customer'],
                'ordered_qty' => ['type' => 'number', 'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Ordered Qty', 'description' => 'Quantity requested by customer'],
                'picked_qty'  => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Picked Qty',   'description' => 'Quantity confirmed picked from stock'],
            ], 'foreign_keys' => [
                'order_id'   => ['reference_table' => 'orders',   'reference_column' => 'id', 'display_column' => 'order_number'],
                'product_id' => ['reference_table' => 'products', 'reference_column' => 'id', 'display_column' => 'sku'],
            ]],

            'shipments' => ['display_name' => 'Shipments', 'schema' => 'spw_wms', 'icon' => 'assets/icons/delivery_truck_speed.png', 'columns' => [
                'id'               => ['type' => 'number',    'display_name' => 'ID',              'description' => 'Unique shipment identifier'],
                'order_id'         => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Order',           'description' => 'Customer order this shipment fulfils'],
                'carrier'          => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Carrier',         'description' => 'Logistics carrier (DHL, FedEx, etc.)'],
                'tracking_number'  => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Tracking #',      'description' => 'Carrier tracking number'],
                'dispatched_at'    => ['type' => 'timestamp', 'show_in_grid' => true, 'display_name' => 'Dispatched At',   'description' => 'Date/time shipment left warehouse'],
                'delivered_at'     => ['type' => 'timestamp', 'show_in_grid' => true, 'display_name' => 'Delivered At',    'description' => 'Date/time confirmed delivery (NULL = in transit)'],
                'created_at'       => ['type' => 'timestamp', 'readonly' => true, 'display_name' => 'Created At',     'description' => 'Record creation date'],
            ], 'foreign_keys' => [
                'order_id' => ['reference_table' => 'orders', 'reference_column' => 'id', 'display_column' => 'order_number'],
            ]],
        ],
        'dashboard_widgets' => [
            ['id' => 'demo_wms_001', 'type' => 'stat_card', 'title' => 'Warehouses',      'table' => 'warehouses',      'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/warehouse.png',          'color' => '#e2b932', 'display_columns' => []],
            ['id' => 'demo_wms_002', 'type' => 'stat_card', 'title' => 'Products',        'table' => 'products',        'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/package_2.png',          'color' => '#bb53d0', 'display_columns' => []],
            ['id' => 'demo_wms_007', 'type' => 'stat_card', 'title' => 'Low Stock Items', 'table' => 'stock',           'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'quantity', 'op' => '<', 'val' => 'min_quantity']]], 'icon' => 'assets/icons/inventory.png', 'color' => '#d71919', 'display_columns' => []],
            ['id' => 'demo_wms_004', 'type' => 'bar_chart', 'title' => 'Movements by Type','table' => 'movements',      'width' => 2, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'type',   'conditions' => []], 'icon' => 'assets/icons/arrow_split.png',   'color' => '#c4b5fd', 'display_columns' => []],
            ['id' => 'demo_wms_006', 'type' => 'pie_chart', 'title' => 'Stock by Category','table' => 'products',       'width' => 1, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'category','conditions' => []], 'icon' => 'assets/icons/package_2.png',     'color' => '#93c5fd', 'display_columns' => []],
            ['id' => 'demo_wms_009', 'type' => 'stat_card', 'title' => 'Open Orders',     'table' => 'orders',          'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'status', 'op' => '!=', 'val' => 'Delivered'], ['col' => 'status', 'op' => '!=', 'val' => 'Cancelled', 'logic' => 'AND']]], 'icon' => 'assets/icons/shopping_cart.png', 'color' => '#289f6f', 'display_columns' => []],
            ['id' => 'demo_wms_008', 'type' => 'stat_card', 'title' => 'Pending POs',     'table' => 'purchase_orders', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => [['col' => 'status', 'op' => '!=', 'val' => 'Received'], ['col' => 'status', 'op' => '!=', 'val' => 'Cancelled', 'logic' => 'AND']]], 'icon' => 'assets/icons/ballot.png',       'color' => '#553eb1', 'display_columns' => []],
            ['id' => 'demo_wms_010', 'type' => 'bar_chart', 'title' => 'Orders by Status', 'table' => 'orders',         'width' => 1, 'height' => 1, 'query' => ['type' => 'group_by', 'group_column' => 'status', 'conditions' => []], 'icon' => 'assets/icons/shopping_cart.png', 'color' => '#93c5fd', 'display_columns' => []],
        ],
        'calendar_sources' => [
            ['table' => 'movements',       'date_column' => 'moved_at',       'title_column' => 'type',         'color' => '#fcd34d', 'notify_before_days' => 0, 'url_template' => 'edit.php?table=movements&id={id}',       'icon' => 'assets/icons/arrow_split.png',    'notified_users' => []],
            ['table' => 'purchase_orders', 'date_column' => 'expected_date',  'title_column' => 'po_number',    'color' => '#93c5fd', 'notify_before_days' => 2, 'url_template' => 'edit.php?table=purchase_orders&id={id}', 'icon' => 'assets/icons/ballot.png',         'notified_users' => []],
            ['table' => 'orders',          'date_column' => 'required_date',  'title_column' => 'order_number', 'color' => '#6ee7b7', 'notify_before_days' => 2, 'url_template' => 'edit.php?table=orders&id={id}',          'icon' => 'assets/icons/shopping_cart.png',  'notified_users' => []],
            ['table' => 'batches',         'date_column' => 'expiry_date',    'title_column' => 'batch_number', 'color' => '#f87171', 'notify_before_days' => 14,'url_template' => 'edit.php?table=batches&id={id}',         'icon' => 'assets/icons/inventory.png',      'notified_users' => []],
        ],
        'workflows' => [
            ['id' => 'wf_demo_wms_001', 'title' => 'New Stock Entry', 'icon' => 'assets/icons/warehouse.png', 'description' => 'WMS: add product → register batch → set stock → log movement.', 'steps' => [
                ['title' => 'Add Product',    'table' => 'products',  'foreign_key' => '',            'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Register Batch', 'table' => 'batches',   'foreign_key' => 'product_id',  'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Set Stock',      'table' => 'stock',     'foreign_key' => 'product_id',  'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Log Inbound',    'table' => 'movements', 'foreign_key' => 'product_id',  'link_to_step' => 0, 'allow_multiple' => true],
            ]],
            ['id' => 'wf_demo_wms_002', 'title' => 'Goods Receipt (GRN)', 'icon' => 'assets/icons/ballot.png', 'description' => 'WMS: create supplier → raise PO → add PO lines.', 'steps' => [
                ['title' => 'Add Supplier',  'table' => 'suppliers',       'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Create PO',     'table' => 'purchase_orders', 'foreign_key' => 'supplier_id','link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add PO Lines',  'table' => 'po_items',        'foreign_key' => 'po_id',      'link_to_step' => 1, 'allow_multiple' => true],
            ]],
            ['id' => 'wf_demo_wms_003', 'title' => 'Order Fulfillment', 'icon' => 'assets/icons/shopping_cart.png', 'description' => 'WMS: create customer → raise order → add lines → dispatch.', 'steps' => [
                ['title' => 'Add Customer',   'table' => 'customers',    'foreign_key' => '',             'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Create Order',   'table' => 'orders',       'foreign_key' => 'customer_id',  'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Order Lines','table' => 'order_items',  'foreign_key' => 'order_id',     'link_to_step' => 1, 'allow_multiple' => true],
                ['title' => 'Create Shipment','table' => 'shipments',    'foreign_key' => 'order_id',     'link_to_step' => 1, 'allow_multiple' => false],
            ]],
        ],
        'views' => [
            'v_demo_wms_stock' => ['schema' => 'spw_wms', 'display_name' => 'WMS Stock Overview', 'menu_name' => 'Stock', 'icon' => 'assets/icons/warehouse.png', 'hidden' => false, 'description' => 'Stock levels by product, warehouse, zone.', 'columns' => [
                'sku'          => ['display_name' => 'SKU'],
                'product'      => ['display_name' => 'Product'],
                'category'     => ['display_name' => 'Category'],
                'warehouse'    => ['display_name' => 'Warehouse'],
                'zone'         => ['display_name' => 'Zone'],
                'quantity'     => ['display_name' => 'Qty',     'summary' => 'sum'],
                'min_quantity' => ['display_name' => 'Min',     'summary' => 'sum'],
                'low_stock'    => ['display_name' => 'Low?', 'color_rules' => [['op' => '=', 'value' => 'true', 'color' => '#f87171']]],
            ], 'drill_down' => ['enabled' => false]],

            'v_demo_wms_low_stock' => ['schema' => 'spw_wms', 'display_name' => 'Low Stock Alert', 'menu_name' => 'Low Stock', 'icon' => 'assets/icons/inventory.png', 'hidden' => false, 'description' => 'Products below reorder threshold, sorted by shortage.', 'columns' => [
                'sku'          => ['display_name' => 'SKU'],
                'product'      => ['display_name' => 'Product'],
                'warehouse'    => ['display_name' => 'Warehouse'],
                'quantity'     => ['display_name' => 'On Hand', 'summary' => 'sum'],
                'min_quantity' => ['display_name' => 'Min',     'summary' => 'sum'],
                'shortage'     => ['display_name' => 'Shortage','summary' => 'sum', 'color_rules' => [['op' => '>', 'value' => '0', 'color' => '#f87171']]],
            ], 'drill_down' => ['enabled' => false]],

            'v_demo_wms_po_status' => ['schema' => 'spw_wms', 'display_name' => 'PO Status', 'menu_name' => 'PO Status', 'icon' => 'assets/icons/ballot.png', 'hidden' => false, 'description' => 'Open purchase orders with expected delivery and value.', 'columns' => [
                'po_number'    => ['display_name' => 'PO #'],
                'status'       => ['display_name' => 'Status'],
                'supplier'     => ['display_name' => 'Supplier'],
                'warehouse'    => ['display_name' => 'Destination'],
                'expected_date' => ['display_name' => 'Expected'],
                'line_count'   => ['display_name' => 'Lines',      'summary' => 'sum'],
                'total_value'  => ['display_name' => 'Value (€)', 'summary' => 'sum'],
            ], 'drill_down' => ['enabled' => false]],

            'v_demo_wms_expiring_soon' => ['schema' => 'spw_wms', 'display_name' => 'Expiring Batches', 'menu_name' => 'Expiring Soon', 'icon' => 'assets/icons/inventory.png', 'hidden' => false, 'description' => 'Batches expiring within 90 days, sorted by date.', 'columns' => [
                'batch_number'  => ['display_name' => 'Batch #'],
                'sku'           => ['display_name' => 'SKU'],
                'product'       => ['display_name' => 'Product'],
                'expiry_date'   => ['display_name' => 'Expiry Date'],
                'batch_status'  => ['display_name' => 'Status'],
                'qty_on_hand'   => ['display_name' => 'Qty On Hand', 'summary' => 'sum'],
                'days_to_expiry' => ['display_name' => 'Days Left', 'color_rules' => [['op' => '<', 'value' => '30', 'color' => '#f87171'], ['op' => '<', 'value' => '60', 'color' => '#fcd34d']]],
            ], 'drill_down' => ['enabled' => false]],

            'v_demo_wms_inventory_value' => ['schema' => 'spw_wms', 'display_name' => 'Inventory Value', 'menu_name' => 'Inventory Value', 'icon' => 'assets/icons/payments.png', 'hidden' => false, 'description' => 'Stock value by warehouse → category → product.', 'columns' => [
                'warehouse'   => ['display_name' => 'Warehouse'],
                'category'    => ['display_name' => 'Category'],
                'sku'         => ['display_name' => 'SKU',         'aggregate' => ''],
                'product'     => ['display_name' => 'Product',     'aggregate' => ''],
                'quantity'    => ['display_name' => 'Qty',         'aggregate' => 'sum', 'summary' => 'sum'],
                'unit_price'  => ['display_name' => 'Unit Price',  'aggregate' => ''],
                'stock_value' => ['display_name' => 'Value (€)',  'aggregate' => 'sum', 'summary' => 'sum'],
            ], 'drill_down' => ['enabled' => true, 'levels' => [
                ['group_by' => 'warehouse', 'label' => 'Warehouse'],
                ['group_by' => 'category',  'label' => 'Category'],
            ]]],
        ],
        'menu_items' => [
            ['key' => 'suppliers', 'children' => [
                ['key' => 'purchase_orders'],
                ['key' => 'po_items'],
            ]],
            ['key' => 'warehouses', 'children' => [
                ['key' => 'locations'],
                ['key' => 'stock'],
                ['key' => 'movements'],
            ]],
            ['key' => 'products', 'children' => [
                ['key' => 'batches'],
            ]],
            ['key' => 'customers', 'children' => [
                ['key' => 'orders'],
                ['key' => 'order_items'],
                ['key' => 'shipments'],
            ]],
        ],
    ];
}
