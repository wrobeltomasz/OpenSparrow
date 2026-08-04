<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

// admin/demo/crm.php — CRM demo app definition (data only, no auth/routing)
// demo_def_crm($conn): returns the spw_crm schema spec — DDL (companies, contacts, deals, activities, leads), view names, seed data,
// plus config payloads: dashboard widgets, calendar sources, Kanban board, workflows, views, menu, files relations, automations (incl. email action), anonymization rules, print templates and User Records column mapping
// Consumed by demo/seed.php during demo_install

function demo_def_crm($conn): array
{
    return [
        'pg_schema'  => 'spw_crm',
        // Every view this definition creates, for the uninstall drop pass.
        'view_names' => ['v_demo_crm_company_pipeline', 'v_demo_crm_leads_funnel', 'v_demo_crm_pipeline_report', 'v_demo_crm_activity_agenda'],
        'ddl' => [
            'CREATE SCHEMA IF NOT EXISTS spw_crm',
            "CREATE TABLE IF NOT EXISTS spw_crm.companies (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, industry VARCHAR(100), website VARCHAR(255), phone VARCHAR(50), email VARCHAR(255), created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_crm.contacts (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255), phone VARCHAR(50), position VARCHAR(100), created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_crm.deals (id SERIAL PRIMARY KEY, company_id INTEGER REFERENCES spw_crm.companies(id) ON DELETE SET NULL, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, title VARCHAR(255) NOT NULL, value NUMERIC(12,2), stage VARCHAR(50) DEFAULT 'Lead', expected_close DATE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_crm.activities (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE CASCADE, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, type VARCHAR(50) DEFAULT 'Call', notes TEXT, scheduled_at TIMESTAMP, done BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_crm.leads (id SERIAL PRIMARY KEY, source VARCHAR(50) DEFAULT 'Web', first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255), phone VARCHAR(50), company_name VARCHAR(255), status VARCHAR(50) DEFAULT 'New', converted_contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE SET NULL, created_at TIMESTAMP DEFAULT NOW())",
            // Many-to-many: extra stakeholder contacts on a deal, beyond the single
            // primary deals.contact_id FK — showcases the many_to_many field type on
            // a table that stays visible in the slimmed-down demo menu.
            "CREATE TABLE IF NOT EXISTS spw_crm.deal_contacts (id SERIAL PRIMARY KEY, deal_id INTEGER REFERENCES spw_crm.deals(id) ON DELETE CASCADE, contact_id INTEGER REFERENCES spw_crm.contacts(id) ON DELETE CASCADE, role VARCHAR(100), added_at TIMESTAMP DEFAULT NOW())",
            // Company x stage aggregate feeding the Pipeline Summary view: deal count /
            // value stats, the expected-close window and overdue count per group, plus
            // activity totals. Activities are pre-aggregated per deal in the subquery so
            // the join fan-out cannot skew COUNT(d.id) / AVG / MAX / MIN over d.value.
            // Contact count is a correlated subquery — it belongs to the company, not to
            // the company x stage group, so it repeats across a company's stage rows.
            'CREATE OR REPLACE VIEW spw_crm.v_demo_crm_company_pipeline AS '
                . 'SELECT c.name AS company_name, c.industry AS industry, d.stage AS stage, '
                . '(SELECT COUNT(*) FROM spw_crm.contacts ct WHERE ct.company_id = c.id) AS contact_count, '
                . 'COUNT(d.id) AS deal_count, '
                . 'COALESCE(SUM(d.value), 0) AS total_value, '
                . 'ROUND(AVG(d.value), 2) AS avg_deal, '
                . 'MAX(d.value) AS max_deal, '
                . 'MIN(d.value) AS min_deal, '
                . 'ROUND(STDDEV_SAMP(d.value), 2) AS stddev_deal, '
                . 'MIN(d.expected_close) AS first_close, '
                . 'MAX(d.expected_close) AS last_close, '
                . "COUNT(*) FILTER (WHERE d.expected_close < CURRENT_DATE "
                . "AND d.stage NOT IN ('Won', 'Lost')) AS overdue_deals, "
                . 'COALESCE(SUM(act.cnt), 0) AS activity_count, '
                . 'COALESCE(SUM(act.done_cnt), 0) AS activity_done, '
                . 'COALESCE(SUM(act.cnt) - SUM(act.done_cnt), 0) AS activity_open, '
                . 'ROUND(AVG(act.cnt), 2) AS avg_activities_per_deal '
                . 'FROM spw_crm.deals d '
                . 'JOIN spw_crm.companies c ON c.id = d.company_id '
                . 'LEFT JOIN (SELECT deal_id, COUNT(*) AS cnt, '
                . 'COUNT(*) FILTER (WHERE done) AS done_cnt '
                . 'FROM spw_crm.activities GROUP BY deal_id) act ON act.deal_id = d.id '
                . 'GROUP BY c.id, c.name, c.industry, d.stage '
                . 'ORDER BY c.name, d.stage',
            'CREATE OR REPLACE VIEW spw_crm.v_demo_crm_leads_funnel AS SELECT status, COUNT(*) AS lead_count FROM spw_crm.leads GROUP BY status ORDER BY status',
            // Report views for the print templates below — multi-row lists over the tables
            // the demo actually exposes (deals / activities), joined out to company and
            // contact. 'done_label' is a text mirror of activities.done: the print
            // parameter picker runs DISTINCT on the bound column, and a raw boolean would
            // offer 't'/'f' in the dropdown (same trick as 'paid' in the revenue view).
            'CREATE OR REPLACE VIEW spw_crm.v_demo_crm_pipeline_report AS '
                . 'SELECT d.id, d.title, d.stage, d.value, d.expected_close, '
                . 'c.name AS company_name, c.industry AS company_industry, '
                . "TRIM(CONCAT_WS(' ', ct.first_name, ct.last_name)) AS contact_name, "
                . 'ct.email AS contact_email '
                . 'FROM spw_crm.deals d '
                . 'LEFT JOIN spw_crm.companies c ON c.id = d.company_id '
                . 'LEFT JOIN spw_crm.contacts ct ON ct.id = d.contact_id '
                . 'ORDER BY c.name, d.expected_close',
            'CREATE OR REPLACE VIEW spw_crm.v_demo_crm_activity_agenda AS '
                . 'SELECT a.id, a.scheduled_at::date AS scheduled_on, a.type, a.notes, '
                . "CASE WHEN a.done THEN 'Yes' ELSE 'No' END AS done_label, "
                . 'd.title AS deal_title, '
                . 'c.name AS company_name, '
                . "TRIM(CONCAT_WS(' ', ct.first_name, ct.last_name)) AS contact_name "
                . 'FROM spw_crm.activities a '
                . 'LEFT JOIN spw_crm.deals d ON d.id = a.deal_id '
                . 'LEFT JOIN spw_crm.companies c ON c.id = d.company_id '
                . 'LEFT JOIN spw_crm.contacts ct ON ct.id = a.contact_id '
                . 'ORDER BY a.scheduled_at DESC',
            // Validation procedure for the "Add Contact" workflow below: the wizard
            // CALLs it when the user clicks "Next step", and a RAISE EXCEPTION here
            // blocks the step and surfaces the message in the UI. Phone separators
            // (- . space parentheses) are stripped before the digit check, so
            // "+1-555-1001" and "+1 (555) 1001" both pass.
            'CREATE OR REPLACE PROCEDURE spw_crm.validate_contact(p_email text, p_phone text) '
                . 'LANGUAGE plpgsql AS $proc$ '
                . 'DECLARE v_digits text; '
                . 'BEGIN '
                . 'IF p_email IS NULL OR p_email NOT LIKE \'%@%\' THEN '
                . 'RAISE EXCEPTION \'Invalid email: %\', COALESCE(p_email, \'(empty)\'); '
                . 'END IF; '
                . 'v_digits := regexp_replace(COALESCE(p_phone, \'\'), \'[-. ()]\', \'\', \'g\'); '
                . 'IF v_digits <> \'\' AND v_digits !~ \'^\\+?[0-9]{6,15}$\' THEN '
                . 'RAISE EXCEPTION \'Invalid phone: % — digits only, separators - . ( ) and spaces are allowed\', p_phone; '
                . 'END IF; '
                . 'END $proc$',
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
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Zenith Technologies', 'Technology', 'zenithtech.com', '+1-555-2001', 'contact@zenithtech.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Nexus Consulting Group', 'Consulting', 'nexusgroup.io', '+1-555-2002', 'hello@nexusgroup.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ProVision Software', 'Software', 'provision.com', '+1-555-2003', 'sales@provision.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Apex IT Solutions', 'IT Services', 'apexitsol.net', '+1-555-2004', 'support@apexitsol.net')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Creative Minds Agency', 'Digital Agency', 'creativeminds.io', '+1-555-2005', 'team@creativeminds.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('SkyCloud Infrastructure', 'Cloud Services', 'skycloud.io', '+1-555-2006', 'ops@skycloud.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('InsightData Corp', 'Analytics', 'insightdata.io', '+1-555-2007', 'info@insightdata.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('SafeGuard Security', 'Cybersecurity', 'safeguardsec.com', '+1-555-2008', 'hello@safeguardsec.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('FutureWorks R&D', 'R&D', 'futureworks.io', '+1-555-2009', 'research@futureworks.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('StrategyPoint Partners', 'Management Consulting', 'strategypoint.com', '+1-555-2010', 'contact@strategypoint.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ProServe Business', 'Business Services', 'proserve.com', '+1-555-2011', 'sales@proserve.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CodeFlow Systems', 'Enterprise Software', 'codeflow.io', '+1-555-2012', 'support@codeflow.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Growth Capital Fund', 'Private Equity', 'growthcapital.io', '+1-555-2013', 'invest@growthcapital.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('BrandBoost Marketing', 'Marketing Services', 'brandboost.com', '+1-555-2014', 'team@brandboost.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Venture Nexus Fund', 'Venture Capital', 'venturenexus.io', '+1-555-2015', 'hello@venturenexus.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Quantum Innovations', 'Technology', 'quantuminnovate.com', '+1-555-3001', 'info@quantuminnovate.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Elite Advisory Group', 'Consulting', 'eliteadvisory.io', '+1-555-3002', 'contact@eliteadvisory.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CodeBase Solutions', 'Software', 'codebase.io', '+1-555-3003', 'sales@codebase.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('System Pro Services', 'IT Services', 'systempro.net', '+1-555-3004', 'support@systempro.net')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Pixel Perfect Design', 'Digital Agency', 'pixelperfect.com', '+1-555-3005', 'hello@pixelperfect.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CloudScale Solutions', 'Cloud Services', 'cloudscale.io', '+1-555-3006', 'ops@cloudscale.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('DataVault Analytics', 'Analytics', 'datavault.io', '+1-555-3007', 'team@datavault.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CyberDefense Plus', 'Cybersecurity', 'cyberdefense.com', '+1-555-3008', 'sales@cyberdefense.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('NextGen Labs', 'R&D', 'nextgenlabs.io', '+1-555-3009', 'research@nextgenlabs.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ConsultPro Advisors', 'Management Consulting', 'consultpro.com', '+1-555-3010', 'info@consultpro.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Enterprise Plus Group', 'Business Services', 'enterpriseplus.io', '+1-555-3011', 'contact@enterpriseplus.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('SoftwarePro Enterprise', 'Enterprise Software', 'softwarepro.com', '+1-555-3012', 'support@softwarepro.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Capital Growth Partners', 'Private Equity', 'capitalgrowth.io', '+1-555-3013', 'hello@capitalgrowth.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('MarketPro Services', 'Marketing Services', 'marketpro.com', '+1-555-3014', 'team@marketpro.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Innovation Ventures Inc', 'Venture Capital', 'innovventures.io', '+1-555-3015', 'invest@innovventures.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Digital Transformation Ltd', 'Technology', 'digittrans.com', '+1-555-4001', 'sales@digittrans.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Global Strategy Partners', 'Consulting', 'globalstrat.io', '+1-555-4002', 'info@globalstrat.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Advanced Software Group', 'Software', 'advsoft.io', '+1-555-4003', 'contact@advsoft.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Complete IT Solutions', 'IT Services', 'completeit.net', '+1-555-4004', 'support@completeit.net')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Digital Studio Pro', 'Digital Agency', 'digitalstudio.com', '+1-555-4005', 'hello@digitalstudio.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Hybrid Cloud Services', 'Cloud Services', 'hybridcloud.io', '+1-555-4006', 'ops@hybridcloud.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Precision Analytics', 'Analytics', 'precisionanalytics.io', '+1-555-4007', 'team@precisionanalytics.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ForceShield Security', 'Cybersecurity', 'forceshield.com', '+1-555-4008', 'sales@forceshield.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Innovation Station', 'R&D', 'innovationstation.io', '+1-555-4009', 'research@innovationstation.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Executive Counsel Group', 'Management Consulting', 'execounsel.com', '+1-555-4010', 'contact@execounsel.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Total Solutions Group', 'Business Services', 'totalsol.io', '+1-555-4011', 'info@totalsol.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Premier Software Systems', 'Enterprise Software', 'premiersoftware.com', '+1-555-4012', 'support@premiersoftware.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Equity Partners LLC', 'Private Equity', 'equitypartners.io', '+1-555-4013', 'hello@equitypartners.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Momentum Marketing', 'Marketing Services', 'momentummarket.com', '+1-555-4014', 'team@momentummarket.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('VenturePath Capital', 'Venture Capital', 'venturepath.io', '+1-555-4015', 'invest@venturepath.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('TechCore Innovations', 'Technology', 'techcore.com', '+1-555-5001', 'sales@techcore.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Advisory Solutions Plus', 'Consulting', 'advisoryplus.io', '+1-555-5002', 'info@advisoryplus.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('SoftHub Technologies', 'Software', 'softhub.io', '+1-555-5003', 'contact@softhub.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Infrastructure Pro', 'IT Services', 'infrastructpro.net', '+1-555-5004', 'support@infrastructpro.net')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Spectrum Digital Agency', 'Digital Agency', 'spectrumdigital.com', '+1-555-5005', 'hello@spectrumdigital.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('CloudEdge Services', 'Cloud Services', 'cloudedge.io', '+1-555-5006', 'ops@cloudedge.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ByteInsights Analytics', 'Analytics', 'byteinsights.io', '+1-555-5007', 'team@byteinsights.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('ShieldForce Security', 'Cybersecurity', 'shieldforce.com', '+1-555-5008', 'sales@shieldforce.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Breakthrough Research', 'R&D', 'breakthroughresearch.io', '+1-555-5009', 'research@breakthroughresearch.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Premier Advisory Group', 'Management Consulting', 'premieradvisory.com', '+1-555-5010', 'contact@premieradvisory.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Integrated Services Corp', 'Business Services', 'integratedservices.io', '+1-555-5011', 'info@integratedservices.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Elite Software Partners', 'Enterprise Software', 'elitesoftware.com', '+1-555-5012', 'support@elitesoftware.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Strategic Equity Fund', 'Private Equity', 'strategicequity.io', '+1-555-5013', 'hello@strategicequity.io')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('DigitalEdge Marketing', 'Marketing Services', 'digitaledge.com', '+1-555-5014', 'team@digitaledge.com')",
            "INSERT INTO spw_crm.companies (name, industry, website, phone, email) VALUES ('Prospect Ventures Inc', 'Venture Capital', 'prospectventures.io', '+1-555-5015', 'invest@prospectventures.io')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (1, 'John', 'Smith', 'john.smith@acme.com', '+1-555-2001', 'Sales Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (1, 'Sarah', 'Johnson', 'sarah.j@acme.com', '+1-555-2002', 'Product Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (2, 'Michael', 'Brown', 'mbrown@globalsol.com', '+1-555-2003', 'Chief Strategy Officer')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (3, 'Emma', 'Wilson', 'emma.w@techvision.io', '+1-555-2004', 'Head of Sales')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (4, 'David', 'Miller', 'david.m@entsys.net', '+1-555-2005', 'IT Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (5, 'Lisa', 'Garcia', 'lisa.g@diginnovate.com', '+1-555-2006', 'Creative Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (1, 'Robert', 'Taylor', 'robert.t@acme.com', '+1-555-2007', 'Operations Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (2, 'Jennifer', 'Martinez', 'jennifer.m@globalsol.com', '+1-555-2008', 'Senior Consultant')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (3, 'Christopher', 'Anderson', 'chris.a@techvision.io', '+1-555-2009', 'VP Engineering')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (4, 'Michelle', 'Thompson', 'michelle.t@entsys.net', '+1-555-2010', 'Business Analyst')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (5, 'James', 'White', 'james.w@diginnovate.com', '+1-555-2011', 'Account Executive')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (6, 'Patricia', 'Harris', 'patricia.h@cloudfirst.io', '+1-555-2012', 'Solutions Architect')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (7, 'Andrew', 'Clark', 'andrew.c@datastream.io', '+1-555-2013', 'Data Engineer')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (8, 'Karen', 'Lewis', 'karen.l@securenet.com', '+1-555-2014', 'Chief Security Officer')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (9, 'Daniel', 'Walker', 'daniel.w@innovatelabs.io', '+1-555-2015', 'R&D Lead')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (10, 'Nancy', 'Hall', 'nancy.h@brightbridge.com', '+1-555-2016', 'Managing Principal')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (11, 'Mark', 'Young', 'mark.y@nextgendyn.com', '+1-555-2017', 'Client Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (12, 'Susan', 'King', 'susan.k@vertexsol.com', '+1-555-2018', 'Sales Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (13, 'Paul', 'Scott', 'paul.s@momentum.io', '+1-555-2019', 'Investment Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (14, 'Rebecca', 'Green', 'rebecca.g@purescale.com', '+1-555-2020', 'Campaign Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (15, 'Thomas', 'Adams', 'thomas.a@quantumleap.io', '+1-555-2021', 'Investment Partner')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (16, 'Angela', 'Nelson', 'angela.n@zenithtech.com', '+1-555-2022', 'Platform Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (17, 'Kevin', 'Carter', 'kevin.c@nexusgroup.io', '+1-555-2023', 'Engagement Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (18, 'Lisa', 'Mitchell', 'lisa.m@provision.com', '+1-555-2024', 'Product Lead')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (19, 'George', 'Roberts', 'george.r@apexitsol.net', '+1-555-2025', 'Infrastructure Manager')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (20, 'Maria', 'Phillips', 'maria.p@creativeminds.io', '+1-555-2026', 'Creative Lead')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (21, 'Steven', 'Campbell', 'steven.c@skycloud.io', '+1-555-2027', 'Cloud Operations Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (22, 'Dorothy', 'Parker', 'dorothy.p@insightdata.io', '+1-555-2028', 'Analytics Head')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (23, 'Edward', 'Evans', 'edward.e@safeguardsec.com', '+1-555-2029', 'Security Director')",
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) VALUES (24, 'Barbara', 'Edwards', 'barbara.e@futureworks.io', '+1-555-2030', 'Research Manager')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (1, 1, 'Enterprise License Q2', 45000.00, 'Proposal', '2026-06-30')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (2, 3, 'Digital Transformation Project', 120000.00, 'Negotiation', '2026-07-15')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (3, 4, 'Cloud Migration Services', 85000.00, 'Qualified', '2026-06-01')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (4, 5, 'Support & Maintenance', 35000.00, 'Won', '2026-05-20')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (5, 6, 'Marketing Campaign Development', 55000.00, 'Lead', '2026-08-01')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (1, 2, 'Integration Consulting', 25000.00, 'Proposal', '2026-07-01')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (6, 7, 'Cloud Infrastructure Buildout', 95000.00, 'Qualified', '2026-06-15')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (7, 8, 'Data Analytics Platform', 150000.00, 'Negotiation', '2026-07-30')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (8, 9, 'Security Audit & Remediation', 65000.00, 'Proposal', '2026-06-20')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (9, 10, 'Innovation Research Program', 75000.00, 'Lead', '2026-08-15')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (10, 11, 'Strategic Business Review', 40000.00, 'Won', '2026-05-30')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (11, 12, 'Enterprise Services Package', 110000.00, 'Negotiation', '2026-07-10')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (12, 13, 'Software Platform Implementation', 180000.00, 'Qualified', '2026-08-01')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (13, 14, 'Growth Fund Round A', 500000.00, 'Negotiation', '2026-08-30')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (14, 15, 'Brand Strategy & Marketing', 85000.00, 'Proposal', '2026-06-25')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (15, 16, 'Seed Investment Series', 250000.00, 'Lead', '2026-09-01')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (16, 17, 'Technology Stack Modernization', 95000.00, 'Qualified', '2026-07-05')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (17, 18, 'Business Process Consulting', 65000.00, 'Proposal', '2026-06-10')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (18, 19, 'Application Development', 120000.00, 'Lead', '2026-08-20')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (19, 20, 'System Integration & APIs', 78000.00, 'Won', '2026-05-25')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (20, 21, 'Digital Marketing Campaign', 55000.00, 'Proposal', '2026-06-18')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (21, 22, 'Cloud Architecture Redesign', 105000.00, 'Negotiation', '2026-07-22')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (22, 23, 'Business Intelligence Suite', 145000.00, 'Qualified', '2026-08-10')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (23, 24, 'Threat Assessment & Response', 68000.00, 'Lead', '2026-08-05')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (24, 3, 'Product Innovation Initiative', 95000.00, 'Proposal', '2026-07-12')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (3, 5, 'Infrastructure Expansion Phase 2', 125000.00, 'Negotiation', '2026-08-25')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (6, 9, 'Premium Support Contract', 42000.00, 'Won', '2026-05-22')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (8, 11, 'Security Operations Center', 160000.00, 'Qualified', '2026-07-20')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (10, 15, 'Executive Coaching Program', 38000.00, 'Lead', '2026-08-12')",
            "INSERT INTO spw_crm.deals (company_id, contact_id, title, value, stage, expected_close) VALUES (12, 20, 'Data Platform Extension', 92000.00, 'Proposal', '2026-09-10')",
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
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (7, 7, 'Call', 'Infrastructure planning discussion', NOW() - INTERVAL '3 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (8, 8, 'Meeting', 'Data platform requirements workshop', NOW() + INTERVAL '5 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (9, 9, 'Email', 'Security assessment questionnaire sent', NOW() - INTERVAL '4 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (10, 10, 'Task', 'Schedule research kickoff meeting', NOW() + INTERVAL '6 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (11, 11, 'Call', 'Strategic planning session', NOW() - INTERVAL '1 day', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (12, 12, 'Meeting', 'Enterprise platform demo', NOW() + INTERVAL '8 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (13, 13, 'Note', 'Fund allocation criteria reviewed', NOW() - INTERVAL '2 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (14, 14, 'Email', 'Marketing deck sent for review', NOW() + INTERVAL '4 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (15, 15, 'Call', 'Seed round discussion with founders', NOW() - INTERVAL '6 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (16, 16, 'Task', 'Tech stack assessment complete', NOW() + INTERVAL '3 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (17, 17, 'Meeting', 'Process mapping and analysis', NOW() - INTERVAL '7 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (18, 18, 'Email', 'Development proposal sent', NOW() + INTERVAL '9 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (19, 19, 'Call', 'API integration design review', NOW() - INTERVAL '4 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (20, 20, 'Note', 'Digital strategy defined', NOW() + INTERVAL '2 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (21, 21, 'Task', 'Cloud migration planning', NOW() - INTERVAL '5 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (22, 22, 'Meeting', 'BI dashboarding requirements', NOW() + INTERVAL '7 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (23, 23, 'Call', 'Security threat modeling session', NOW() - INTERVAL '3 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (24, 24, 'Email', 'R&D roadmap shared', NOW() + INTERVAL '6 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (1, 4, 'Task', 'Budget approval from finance', NOW() - INTERVAL '10 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (2, 6, 'Call', 'Executive steering committee prep', NOW() + INTERVAL '11 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (3, 7, 'Meeting', 'Technical architecture review', NOW() - INTERVAL '6 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (4, 8, 'Email', 'Support SLA document finalized', NOW() + INTERVAL '5 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (5, 9, 'Note', 'Campaign metrics baseline set', NOW() - INTERVAL '9 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (6, 10, 'Task', 'Vendor evaluation criteria', NOW() + INTERVAL '10 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (7, 11, 'Call', 'Stakeholder interviews completed', NOW() - INTERVAL '8 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (8, 12, 'Meeting', 'Data science team alignment', NOW() + INTERVAL '12 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (9, 13, 'Email', 'Remediation plan draft', NOW() - INTERVAL '2 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (10, 14, 'Task', 'Lab resource allocation', NOW() + INTERVAL '8 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (11, 15, 'Call', 'Strategic roadmap alignment', NOW() - INTERVAL '11 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (12, 16, 'Note', 'Contract legal review passed', NOW() + INTERVAL '9 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (13, 17, 'Email', 'LP commitment letters received', NOW() - INTERVAL '13 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (14, 18, 'Task', 'Brand audit and positioning', NOW() + INTERVAL '7 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (15, 19, 'Meeting', 'Due diligence kick-off', NOW() - INTERVAL '14 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (16, 20, 'Call', 'Legacy system migration planning', NOW() + INTERVAL '13 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (17, 21, 'Email', 'Consulting scope statement', NOW() - INTERVAL '7 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (18, 22, 'Task', 'Development environment setup', NOW() + INTERVAL '11 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (19, 23, 'Note', 'Integration test results reviewed', NOW() - INTERVAL '5 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (20, 24, 'Call', 'Digital channel strategy', NOW() + INTERVAL '6 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (21, 3, 'Meeting', 'Cloud capacity planning', NOW() - INTERVAL '9 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (22, 5, 'Email', 'Analytics engine configuration', NOW() + INTERVAL '4 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (23, 7, 'Task', 'Penetration testing approved', NOW() - INTERVAL '6 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (24, 9, 'Call', 'Coaching engagement initiated', NOW() + INTERVAL '10 days', false)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (6, 11, 'Note', 'Payment terms finalized', NOW() - INTERVAL '4 days', true)",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) VALUES (12, 13, 'Email', 'SOC alignment meeting scheduled', NOW() + INTERVAL '3 days', false)",
            // Bulk volume for the Pipeline Summary view (v_demo_crm_company_pipeline):
            // the hand-written rows above only cover companies 1-24 with ~1 deal per
            // company and stage, which makes avg/max/min collapse onto the same number.
            // Two set-based inserts: extra contacts for every company, and 1-3 extra
            // activities per deal (the deal list stays at the 30 hand-written rows above,
            // all of which have deal_contacts m2m links). Activities are scheduled across
            // a 3-month window (previous, current and next month, business hours
            // 08:00-16:00) so the calendar is not clumped into single days. Values derive
            // from id arithmetic rather than random(), so a demo install is reproducible.
            "INSERT INTO spw_crm.contacts (company_id, first_name, last_name, email, phone, position) "
                . "SELECT c.id, "
                . "(ARRAY['Adam','Bella','Carlos','Diana','Elias','Farah','Grace','Hugo','Iris','Jonas','Klara','Liam'])[1 + (c.id + g) % 12], "
                . "(ARRAY['Bauer','Novak','Silva','Okafor','Dubois','Rossi','Hansen','Marek','Ferrara','Larsen','Costa','Weber'])[1 + (c.id * 3 + g) % 12], "
                . "'contact' || c.id || '-' || g || '@' || COALESCE(NULLIF(c.website, ''), 'example.com'), "
                . "'+1-555-' || LPAD((((c.id * 7 + g * 13) % 9000) + 1000)::text, 4, '0'), "
                . "(ARRAY['Account Manager','Procurement Lead','CTO','Operations Manager','Finance Director','Project Manager','Head of IT','Commercial Director'])[1 + (c.id + g * 2) % 8] "
                . "FROM spw_crm.companies c CROSS JOIN generate_series(1, 2) g",
            "INSERT INTO spw_crm.activities (deal_id, contact_id, type, notes, scheduled_at, done) "
                . "SELECT d.id, d.contact_id, "
                . "(ARRAY['Call','Meeting','Email','Task','Note'])[1 + (d.id + g) % 5], "
                . "(ARRAY['Discovery call','Requirements workshop','Proposal sent','Pricing follow-up','Reference check','Contract review'])[1 + (d.id * 3 + g) % 6], "
                . "date_trunc('day', NOW()) "
                . "+ ((((d.id * 17 + g * 29) % 91) - 45)::text || ' days')::interval "
                . "+ ((8 + (d.id * 3 + g * 7) % 9)::text || ' hours')::interval, "
                . "((d.id + g) % 3 <> 0) "
                . "FROM spw_crm.deals d CROSS JOIN generate_series(1, 1 + (d.id % 3)) g",
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
            // Stale leads (created_at > 1 year ago) — matched by the demo anonymization rules, visible in Anonymization dry-run preview
            "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id, created_at) VALUES ('Web', 'Victor', 'Lindqvist', 'victor.l@oldnordic.se', '+46-8-555-0161', 'Old Nordic AB', 'Lost', NULL, NOW() - INTERVAL '18 months')",
            "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id, created_at) VALUES ('Event', 'Helena', 'Novak', 'helena.n@pragueretail.cz', '+420-2-555-0162', 'Prague Retail sro', 'Lost', NULL, NOW() - INTERVAL '2 years')",
            "INSERT INTO spw_crm.leads (source, first_name, last_name, email, phone, company_name, status, converted_contact_id, created_at) VALUES ('Cold Call', 'Bruno', 'Ferreira', 'bruno.f@lisboatech.pt', '+351-21-555-0163', 'Lisboa Tech Lda', 'Lost', NULL, NOW() - INTERVAL '14 months')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (1, 2, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (1, 7, 'Procurement')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (2, 8, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (3, 9, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (4, 10, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (7, 12, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (8, 14, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (12, 18, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (2, 3, 'Executive Sponsor')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (3, 4, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (5, 11, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (6, 1, 'Executive Sponsor')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (6, 7, 'Procurement')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (7, 13, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (8, 15, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (9, 14, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (10, 15, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (11, 16, 'Procurement')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (12, 13, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (13, 19, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (14, 20, 'Executive Sponsor')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (15, 21, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (16, 22, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (17, 23, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (18, 24, 'Procurement')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (19, 25, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (20, 26, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (21, 27, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (22, 28, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (23, 29, 'Executive Sponsor')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (24, 30, 'Procurement')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (25, 24, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (26, 4, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (26, 9, 'Economic Buyer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (27, 12, 'Technical Evaluator')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (28, 14, 'Legal Reviewer')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (29, 16, 'Executive Sponsor')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (30, 18, 'Champion')",
            "INSERT INTO spw_crm.deal_contacts (deal_id, contact_id, role) VALUES (30, 8, 'Procurement')",
            // Spread created_at over past weeks/months so the dashboard period filter
            // (Today/7d/30d) and stat card trend deltas have history to compare against.
            // The WHERE guard on leads keeps the intentionally stale GDPR-demo rows intact.
            "UPDATE spw_crm.companies SET created_at = NOW() - (id % 180) * INTERVAL '1 day'",
            "UPDATE spw_crm.contacts  SET created_at = NOW() - (id % 120) * INTERVAL '1 day'",
            // Deals spread across ~3 months (id * 3, capped at 90 days) so the
            // "Deals Value Over Time" line chart shows several monthly buckets.
            "UPDATE spw_crm.deals     SET created_at = NOW() - ((id * 3) % 90) * INTERVAL '1 day'",
            "UPDATE spw_crm.leads     SET created_at = NOW() - (id % 60)  * INTERVAL '1 day' WHERE created_at >= NOW() - INTERVAL '7 days'",
            // Activities spread across ~2 months so the weekly "Activities Over Time"
            // line chart has history; scheduled_at (calendar) is left untouched.
            "UPDATE spw_crm.activities SET created_at = NOW() - (id % 75) * INTERVAL '1 day'",
        ],
        'schema_tables' => [
            'companies' => ['display_name' => 'Companies', 'schema' => 'spw_crm', 'icon' => 'assets/icons/apartment.png', 'columns' => [
                'id'         => ['type' => 'number', 'show_in_grid' => false, 'display_name' => 'ID', 'description' => 'Unique company identifier'],
                'name'       => ['type' => 'text',   'show_in_grid' => true,  'display_name' => 'Company Name', 'not_null' => true, 'description' => 'Official company name'],
                'industry'   => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Industry', 'description' => 'Industry or sector the company operates in'],
                'website'    => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Website', 'description' => 'Company website URL'],
                'phone'      => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Phone', 'description' => 'Main company phone number'],
                'email'      => ['type' => 'text',   'show_in_grid' => true, 'display_name' => 'Email', 'description' => 'Company email address'],
                'created_at' => ['type' => 'timestamp', 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when company record was created'],
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
                'created_at' => ['type' => 'timestamp', 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when contact record was created'],
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
                'created_at'     => ['type' => 'timestamp', 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when deal record was created'],
            ], 'foreign_keys' => [
                // Contacts are labelled by first + last name everywhere in this demo:
                // the bulk seed below reuses 12 first names across ~180 contacts, so a
                // single-column label makes the picker ambiguous. display_column accepts
                // a list and the parts are joined with " - " (includes/api_helpers.php,
                // src/Repository/FkOptionsLoader.php, grid/cells/fk-cell.js).
                'company_id' => ['reference_table' => 'companies', 'reference_column' => 'id', 'display_column' => 'name'],
                'contact_id' => ['reference_table' => 'contacts',  'reference_column' => 'id', 'display_column' => ['first_name', 'last_name']],
            ], 'subtables' => [
                ['table' => 'activities', 'foreign_key' => 'deal_id', 'label' => 'Activities', 'columns_to_show' => ['type', 'scheduled_at', 'done', 'notes']],
            ], 'many_to_many' => [
                // Single column on purpose, unlike the foreign_keys above: m2m_options()
                // feeds display_column straight to pg_ident() (includes/m2m.php), so a
                // list would be a fatal, not a joined label.
                ['label' => 'Other Stakeholders', 'junction_table' => 'deal_contacts', 'self_fk' => 'deal_id', 'other_fk' => 'contact_id', 'other_table' => 'contacts', 'display_column' => 'last_name'],
            ], 'highlight_rules' => [
                // Evaluated in order, first match wins. Thresholds are picked against the
                // seeded deal values in seed_data above (25k-500k) so both rules fire.
                ['column' => 'value', 'op' => '>=', 'value' => '150000', 'color' => '#dcfce7'],
                ['column' => 'value', 'op' => '<',  'value' => '40000',  'color' => '#fee2e2'],
            ], 'images' => ['enabled' => true, 'label' => 'Attachments', 'max_per_record' => 5, 'show_in_grid' => true]],
            'activities' => ['display_name' => 'Activities', 'schema' => 'spw_crm', 'icon' => 'assets/icons/calendar.png', 'columns' => [
                'id'           => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique activity identifier'],
                'deal_id'      => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Deal', 'description' => 'Deal this activity is associated with'],
                'contact_id'   => ['type' => 'number', 'show_in_grid' => true, 'display_name' => 'Contact', 'description' => 'Contact involved in this activity'],
                'type'         => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Call', 'Email', 'Meeting', 'Task', 'Note'], 'enum_colors' => ['Call' => '#93c5fd', 'Email' => '#6ee7b7', 'Meeting' => '#fcd34d', 'Task' => '#c4b5fd', 'Note' => '#d1d5db'], 'display_name' => 'Type', 'description' => 'Type of activity performed'],
                'notes'        => ['type' => 'text',    'show_in_grid' => false, 'display_name' => 'Notes', 'description' => 'Detailed notes or comments about the activity'],
                'scheduled_at' => ['type' => 'timestamp', 'show_in_grid' => true, 'display_name' => 'Scheduled At', 'description' => 'Date and time activity is scheduled or occurred'],
                'done'         => ['type' => 'boolean', 'show_in_grid' => true, 'enum_colors' => ['true' => '#6ee7b7', 'false' => '#f87171'], 'display_name' => 'Done', 'description' => 'Whether activity is completed'],
                'created_at'   => ['type' => 'timestamp', 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when activity record was created'],
            ], 'foreign_keys' => [
                'deal_id'    => ['reference_table' => 'deals',    'reference_column' => 'id', 'display_column' => 'title'],
                'contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => ['first_name', 'last_name']],
            ]],
            'leads' => ['display_name' => 'Leads', 'schema' => 'spw_crm', 'icon' => 'assets/icons/person_text.png', 'hidden' => true, 'columns' => [
                'id'                   => ['type' => 'number', 'display_name' => 'ID', 'description' => 'Unique lead identifier'],
                'source'               => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['Web', 'Referral', 'Cold Call', 'Event', 'Ads', 'Other'], 'enum_colors' => ['Web' => '#93c5fd', 'Referral' => '#6ee7b7', 'Cold Call' => '#d1d5db', 'Event' => '#fcd34d', 'Ads' => '#c4b5fd', 'Other' => '#d1d5db'], 'display_name' => 'Source', 'description' => 'How this lead was acquired'],
                'first_name'           => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'First Name', 'not_null' => true, 'description' => 'Lead first name'],
                'last_name'            => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Last Name',  'not_null' => true, 'description' => 'Lead last name'],
                'email'                => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Email', 'description' => 'Lead email address'],
                'phone'                => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Phone', 'description' => 'Lead phone number'],
                'company_name'         => ['type' => 'text',    'show_in_grid' => true, 'display_name' => 'Company',    'description' => 'Free-text company name (not yet linked to companies table)'],
                'status'               => ['type' => 'enum',    'show_in_grid' => true, 'options' => ['New', 'Contacted', 'Qualified', 'Lost'], 'enum_colors' => ['New' => '#93c5fd', 'Contacted' => '#fcd34d', 'Qualified' => '#6ee7b7', 'Lost' => '#f87171'], 'display_name' => 'Status', 'description' => 'Lead qualification status'],
                'converted_contact_id' => ['type' => 'number',  'show_in_grid' => false, 'display_name' => 'Converted To', 'description' => 'Contact record created when lead was converted'],
                'created_at'           => ['type' => 'timestamp', 'show_in_grid' => false, 'show_in_edit' => false, 'readonly' => true, 'display_name' => 'Created At', 'description' => 'Date when lead record was created'],
            ], 'foreign_keys' => [
                'converted_contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => ['first_name', 'last_name']],
            ]],
            'deal_contacts' => ['display_name' => 'Deal–Contacts', 'schema' => 'spw_crm', 'hidden' => true, 'columns' => [
                'id'         => ['display_name' => 'ID',      'type' => 'number', 'not_null' => true, 'readonly' => true,  'show_in_grid' => true, 'show_in_edit' => true],
                'deal_id'    => ['display_name' => 'Deal',    'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                'contact_id' => ['display_name' => 'Contact', 'type' => 'number', 'not_null' => true, 'readonly' => false, 'show_in_grid' => true, 'show_in_edit' => true],
                'role'       => ['display_name' => 'Role',    'type' => 'text',   'show_in_grid' => true, 'show_in_edit' => true],
            ], 'foreign_keys' => [
                'deal_id'    => ['reference_table' => 'deals',    'reference_column' => 'id', 'display_column' => 'title'],
                'contact_id' => ['reference_table' => 'contacts', 'reference_column' => 'id', 'display_column' => ['first_name', 'last_name']],
            ], 'subtables' => []],
        ],
        'dashboard_widgets' => [
            ['id' => 'demo_crm_001', 'type' => 'stat_card', 'title' => 'Companies',           'table' => 'companies',  'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/apartment.png',    'color' => '#553eb1', 'display_columns' => []],
            ['id' => 'demo_crm_002', 'type' => 'stat_card', 'title' => 'Contacts',            'table' => 'contacts',   'width' => 1, 'height' => 1, 'query' => ['type' => 'count', 'column' => 'id', 'conditions' => []], 'icon' => 'assets/icons/person.png',       'color' => '#289f6f', 'display_columns' => []],
            ['id' => 'demo_crm_004', 'type' => 'stat_card', 'title' => 'Pipeline Value',       'table' => 'deals',      'width' => 1, 'height' => 1, 'query' => ['type' => 'sum', 'column' => 'value', 'conditions' => [['col' => 'stage', 'op' => '!=', 'val' => 'Won'], ['col' => 'stage', 'op' => '!=', 'val' => 'Lost']]], 'icon' => 'assets/icons/payments.png',     'color' => '#e2b932', 'display_columns' => []],
            ['id' => 'demo_crm_003', 'type' => 'bar_chart', 'title' => 'Deals by Stage',       'table' => 'deals',      'width' => 1, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'stage',  'conditions' => []], 'icon' => 'assets/icons/point_of_sale.png','color' => '#fcd34d', 'display_columns' => []],
            ['id' => 'demo_crm_005', 'type' => 'pie_chart', 'title' => 'Activities Status',    'table' => 'activities', 'width' => 2, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'done',   'conditions' => []], 'icon' => 'assets/icons/calendar.png',     'color' => '#c4b5fd', 'display_columns' => []],
            // Time-series line/area charts — one row (1/3 + 2/3). Both read a spread
            // created_at (see the UPDATE ... created_at seed statements) so the axis
            // covers the last few months.
            ['id' => 'demo_crm_011', 'type' => 'line_chart', 'title' => 'Deals Value Over Time', 'table' => 'deals',      'width' => 1, 'height' => 2, 'query' => ['type' => 'time_series', 'x_column' => 'created_at', 'granularity' => 'month', 'agg_column' => 'value', 'agg_type' => 'sum',   'area' => true, 'conditions' => []], 'icon' => 'assets/icons/point_of_sale.png', 'color' => '#289f6f', 'display_columns' => []],
            ['id' => 'demo_crm_012', 'type' => 'line_chart', 'title' => 'Activities Over Time',  'table' => 'activities', 'width' => 2, 'height' => 2, 'query' => ['type' => 'time_series', 'x_column' => 'created_at', 'granularity' => 'week',  'agg_column' => 'id',    'agg_type' => 'count', 'area' => true, 'conditions' => []], 'icon' => 'assets/icons/calendar.png',      'color' => '#553eb1', 'display_columns' => []],
        ],
        'calendar_sources' => [
            ['table' => 'activities', 'date_column' => 'scheduled_at', 'title_column' => 'type', 'subtitle_column' => 'notes', 'color' => '#93c5fd', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=activities&id={id}', 'icon' => 'assets/icons/calendar.png', 'notified_users' => []],
            ['table' => 'deals', 'date_column' => 'expected_close', 'title_column' => 'title', 'color' => '#fcd34d', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=deals&id={id}', 'icon' => 'assets/icons/point_of_sale.png', 'notified_users' => []],
        ],
        // Kanban board — Deals grouped by their sales Stage. Dragging a card
        // between lanes moves the deal along the pipeline (updates deals.stage).
        // "board" holds a named list (boards[]) — each entry is its own sidebar item.
        'board' => [
            'boards' => [
                [
                    'id'             => 'demo_crm_deals_board',
                    'menu_name'     => 'Deals Board',
                    'menu_icon'     => 'assets/icons/account_tree.png',
                    'hidden'        => false,
                    'table'         => 'deals',
                    'status_column' => 'stage',
                    'title_column'  => 'title',
                    'card_columns'  => ['company_id', 'value', 'expected_close'],
                    'color'         => '#005A9E',
                ],
            ],
        ],
        'workflows' => [
            ['id' => 'wf_demo_crm_001', 'title' => 'New CRM Deal', 'icon' => 'assets/icons/apartment.png', 'description' => 'CRM: add company → contact → deal → activity.', 'steps' => [
                ['title' => 'Add Company',  'table' => 'companies',  'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Contact',  'table' => 'contacts',   'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => true],
                ['title' => 'Create Deal',  'table' => 'deals',      'foreign_key' => 'company_id', 'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Log Activity', 'table' => 'activities', 'foreign_key' => 'deal_id',    'link_to_step' => 2, 'allow_multiple' => true],
            ]],
            ['id' => 'wf_demo_crm_002', 'title' => 'Convert Lead', 'icon' => 'assets/icons/person_text.png', 'description' => 'CRM: lead → company → contact → deal.', 'steps' => [
                ['title' => 'Capture Lead',  'table' => 'leads',     'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Company',   'table' => 'companies', 'foreign_key' => '',           'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Contact',   'table' => 'contacts',  'foreign_key' => 'company_id', 'link_to_step' => 1, 'allow_multiple' => false],
                ['title' => 'Create Deal',   'table' => 'deals',     'foreign_key' => 'company_id', 'link_to_step' => 1, 'allow_multiple' => false],
            ]],
            // Single-step workflow demonstrating the per-step stored-procedure hook:
            // spw_crm.validate_contact runs on "Next step" and rejects a malformed
            // email or phone before anything is written to the database.
            ['id' => 'wf_demo_crm_003', 'title' => 'Add Contact', 'icon' => 'assets/icons/person_text.png', 'description' => 'CRM: add a single contact, validated by a PostgreSQL procedure.', 'steps' => [
                ['title' => 'Add Contact', 'table' => 'contacts', 'foreign_key' => '', 'link_to_step' => 0, 'allow_multiple' => false, 'procedure' => [
                    'enabled' => true,
                    'schema'  => 'spw_crm',
                    'name'    => 'validate_contact',
                    'params'  => [
                        ['source' => 'field', 'step' => 0, 'field' => 'email'],
                        ['source' => 'field', 'step' => 0, 'field' => 'phone'],
                    ],
                ]],
            ]],
        ],
        'views' => [
            // Showcase view: company x stage measures with default row grouping
            // (group_rows) by stage plus per-group aggregates, and drill-down enabled.
            // Mirrors the config as tuned in the admin Views editor.
            'v_demo_crm_company_pipeline' => ['schema' => 'spw_crm', 'source' => 'postgres', 'display_name' => 'CRM Pipeline', 'menu_name' => 'Pipeline Summary', 'icon' => 'assets/icons/point_of_sale.png', 'hidden' => false, 'description' => 'Deal and activity measures per company and sales stage, with subtotals and drill-down by stage and industry.', 'group_rows' => 'stage', 'columns' => [
                'company_name'   => ['display_name' => 'Company',        'aggregate' => 'count'],
                'industry'       => ['display_name' => 'Industry',       'aggregate' => ''],
                'stage'          => ['display_name' => 'Stage',          'aggregate' => ''],
                'contact_count'  => ['display_name' => 'Contacts',       'aggregate' => 'sum', 'color_rules' => [
                    ['op' => '>', 'value' => 3, 'color' => '#d00000'],
                ]],
                'deal_count'     => ['display_name' => 'Deals',          'aggregate' => 'sum'],
                'total_value'    => ['display_name' => 'Total Value',    'aggregate' => 'sum', 'color_rules' => [
                    ['op' => '>', 'value' => 300000, 'color' => '#2b9348'],
                ]],
                'avg_deal'       => ['display_name' => 'Avg Deal',       'aggregate' => 'avg'],
                'max_deal'       => ['display_name' => 'Max Deal',       'aggregate' => 'max'],
                'min_deal'       => ['display_name' => 'Min Deal',       'aggregate' => 'min'],
                'stddev_deal'    => ['display_name' => 'Deal Std Dev',   'aggregate' => 'avg'],
                'first_close'    => ['display_name' => 'First Close',    'aggregate' => 'min'],
                'last_close'     => ['display_name' => 'Last Close',     'aggregate' => 'max'],
                'overdue_deals'  => ['display_name' => 'Overdue',        'aggregate' => 'sum', 'color_rules' => [
                    ['op' => '>', 'value' => 1, 'color' => '#d00000'],
                ]],
                'activity_count' => ['display_name' => 'Activities',     'aggregate' => 'sum'],
                'activity_done'  => ['display_name' => 'Done',           'aggregate' => 'sum'],
                'activity_open'  => ['display_name' => 'Open',           'aggregate' => 'sum'],
                'avg_activities_per_deal' => ['display_name' => 'Avg Activities / Deal', 'aggregate' => 'avg'],
            ], 'drill_down' => ['enabled' => true, 'levels' => []]],
            'v_demo_crm_leads_funnel' => ['schema' => 'spw_crm', 'source' => 'postgres', 'display_name' => 'CRM Leads Funnel', 'menu_name' => 'Leads Funnel', 'icon' => 'assets/icons/account_tree.png', 'hidden' => true, 'description' => 'Lead count by qualification status.', 'columns' => [
                'status'     => ['display_name' => 'Status'],
                'lead_count' => ['display_name' => 'Leads', 'summary' => 'sum'],
            ], 'drill_down' => ['enabled' => false]],
            // Report views backing the print templates — not meant to be browsed as
            // reports, so hidden from the Views module listing.
            'v_demo_crm_pipeline_report' => ['schema' => 'spw_crm', 'source' => 'postgres', 'display_name' => 'Pipeline Report Source', 'menu_name' => 'Pipeline Report Source', 'icon' => 'assets/icons/point_of_sale.png', 'hidden' => true, 'description' => 'One row per deal with joined company and contact fields for the Sales Pipeline Report print template.', 'columns' => [
                'title'          => ['display_name' => 'Deal'],
                'company_name'   => ['display_name' => 'Company'],
                'contact_name'   => ['display_name' => 'Contact'],
                'stage'          => ['display_name' => 'Stage'],
                'value'          => ['display_name' => 'Value'],
                'expected_close' => ['display_name' => 'Expected Close'],
            ], 'drill_down' => ['enabled' => false]],
            'v_demo_crm_activity_agenda' => ['schema' => 'spw_crm', 'source' => 'postgres', 'display_name' => 'Activity Agenda Source', 'menu_name' => 'Activity Agenda Source', 'icon' => 'assets/icons/account_tree.png', 'hidden' => true, 'description' => 'One row per activity with joined deal and contact fields for the Activity Agenda print template.', 'columns' => [
                'scheduled_on' => ['display_name' => 'Scheduled'],
                'type'         => ['display_name' => 'Type'],
                'deal_title'   => ['display_name' => 'Deal'],
                'contact_name' => ['display_name' => 'Contact'],
                'done_label'   => ['display_name' => 'Completed'],
                'notes'        => ['display_name' => 'Notes'],
            ], 'drill_down' => ['enabled' => false]],
        ],
        'prints' => [
            'crm_pipeline_report' => [
                'display_name' => 'Sales Pipeline Report',
                'menu_name'    => 'Pipeline Report',
                'description'  => 'Open and closed deals with company, contact, value and expected close date. Filter by stage or company.',
                'icon'         => 'assets/icons/point_of_sale.png',
                'hidden'       => false,
                'view'         => 'v_demo_crm_pipeline_report',
                'blocks'       => [
                    ['type' => 'header', 'level' => 1, 'text' => 'Sales Pipeline Report'],
                    ['type' => 'text', 'text' => 'Deals by stage, with the owning company and primary contact.'],
                    ['type' => 'table', 'columns' => [
                        ['name' => 'title',          'align' => 'left',   'width' => 26],
                        ['name' => 'company_name',   'align' => 'left',   'width' => 20],
                        ['name' => 'contact_name',   'align' => 'left',   'width' => 18],
                        ['name' => 'stage',          'align' => 'left',   'width' => 12],
                        ['name' => 'value',          'align' => 'right',  'width' => 12],
                        ['name' => 'expected_close', 'align' => 'center', 'width' => 12],
                    ]],
                ],
                'params' => [
                    ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'column' => 'stage', 'required' => false, 'source_view' => 'v_demo_crm_pipeline_report', 'value_column' => 'stage', 'label_column' => 'stage'],
                    ['key' => 'company', 'label' => 'Company', 'type' => 'select', 'column' => 'company_name', 'required' => false, 'source_view' => 'v_demo_crm_pipeline_report', 'value_column' => 'company_name', 'label_column' => 'company_name'],
                ],
            ],
            'crm_activity_agenda' => [
                'display_name' => 'Activity Agenda',
                'menu_name'    => 'Activity Agenda',
                'description'  => 'Scheduled calls, meetings and tasks with the related deal and contact. Filter by type or completion.',
                'icon'         => 'assets/icons/account_tree.png',
                'hidden'       => false,
                'view'         => 'v_demo_crm_activity_agenda',
                'blocks'       => [
                    ['type' => 'header', 'level' => 1, 'text' => 'Activity Agenda'],
                    ['type' => 'text', 'text' => 'Activities in reverse chronological order, newest first.'],
                    ['type' => 'table', 'columns' => [
                        ['name' => 'scheduled_on', 'align' => 'center', 'width' => 12],
                        ['name' => 'type',         'align' => 'left',   'width' => 10],
                        ['name' => 'deal_title',   'align' => 'left',   'width' => 24],
                        ['name' => 'contact_name', 'align' => 'left',   'width' => 18],
                        ['name' => 'done_label',   'align' => 'center', 'width' => 8],
                        ['name' => 'notes',        'align' => 'left',   'width' => 28],
                    ]],
                ],
                'params' => [
                    ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'column' => 'type', 'required' => false, 'source_view' => 'v_demo_crm_activity_agenda', 'value_column' => 'type', 'label_column' => 'type'],
                    ['key' => 'done', 'label' => 'Completed', 'type' => 'select', 'column' => 'done_label', 'required' => false, 'source_view' => 'v_demo_crm_activity_agenda', 'value_column' => 'done_label', 'label_column' => 'done_label'],
                ],
            ],
        ],
        // User Records ("My records" panel) column mapping — which columns are
        // CONCAT_WS'd into each record's label when a user owns a record in that table.
        'user_records' => [
            'companies' => ['name'],
            'contacts'  => ['first_name', 'last_name'],
            'deals'     => ['title'],
            'activities' => ['type'],
            'leads'     => ['first_name', 'last_name'],
        ],
        // Demo users — 3 sample accounts used to seed cross-user comments,
        // per-user "My Notes", record ownership and notifications below. Password
        // is fixed to 'test' for all of them, hashed centrally in seed.php (not
        // stored here). avatar_id is the avatar colour index (OS_AVATAR_COLORS in
        // includes/page_helpers.php) — the avatar itself is the username's initial.
        'demo_users' => [
            ['username' => 'demo.anna',  'role' => 'editor', 'avatar_id' => 3],
            ['username' => 'demo.marek', 'role' => 'editor', 'avatar_id' => 9],
            ['username' => 'demo.julia', 'role' => 'viewer', 'avatar_id' => 15],
        ],
        // Demo comments — cross-user discussion threads seeded onto CRM records.
        // 'author' indexes into demo_users above; related ids are literal, matching
        // the sequential insertion order of seed_data above (fresh spw_crm schema).
        'demo_comments' => [
            ['related_table' => 'deals', 'related_id' => 1, 'author' => 0, 'body' => "Talked to John Smith today, he's leaning towards the annual plan. Sending updated pricing tomorrow."],
            ['related_table' => 'deals', 'related_id' => 1, 'author' => 1, 'body' => 'Good — flag me before you send it, I want to double check the discount tier.'],
            ['related_table' => 'deals', 'related_id' => 1, 'author' => 0, 'body' => 'Will do. Also added the renewal clause they asked for.'],
            ['related_table' => 'deals', 'related_id' => 2, 'author' => 1, 'body' => "Negotiation is dragging — legal on their side wants another round of redlines."],
            ['related_table' => 'deals', 'related_id' => 2, 'author' => 2, 'body' => 'Noted, I will hold off scheduling the kickoff call until this closes.'],
            ['related_table' => 'deals', 'related_id' => 4, 'author' => 0, 'body' => 'Marked as Won — kickoff scheduled with the client.'],
            ['related_table' => 'companies', 'related_id' => 1, 'author' => 1, 'body' => 'Acme is a strategic account this quarter, prioritize their support tickets.'],
            ['related_table' => 'companies', 'related_id' => 1, 'author' => 2, 'body' => 'Got it, tagging their tickets as high priority.'],
            ['related_table' => 'contacts', 'related_id' => 1, 'author' => 0, 'body' => 'John prefers email over calls — keep that in mind for follow-ups.'],
            ['related_table' => 'deals', 'related_id' => 7, 'author' => 2, 'body' => 'Infrastructure buildout timeline looks tight, worth a check-in call this week.'],
            ['related_table' => 'deals', 'related_id' => 3, 'author' => 1, 'body' => 'Emma Wilson confirmed the migration window — first weekend of June works for their ops team.'],
            ['related_table' => 'deals', 'related_id' => 3, 'author' => 0, 'body' => 'That only leaves two weeks for the dry run. Can we move the qualification call up?'],
            ['related_table' => 'deals', 'related_id' => 3, 'author' => 1, 'body' => 'Moved it to Thursday. Emma is fine with it, invite is out.'],
            ['related_table' => 'deals', 'related_id' => 8, 'author' => 2, 'body' => 'DataStream asked for a reference customer of similar size before they sign off.'],
            ['related_table' => 'deals', 'related_id' => 8, 'author' => 0, 'body' => 'I can ask Enterprise Systems — they went live last quarter and were happy with the rollout.'],
            ['related_table' => 'deals', 'related_id' => 8, 'author' => 2, 'body' => 'Perfect, that should unblock the last approval step on their side.'],
            ['related_table' => 'deals', 'related_id' => 14, 'author' => 1, 'body' => 'Round A terms sheet received. Valuation is lower than we modelled, but the tranche schedule is favourable.'],
            ['related_table' => 'deals', 'related_id' => 14, 'author' => 0, 'body' => 'Do we push back on valuation or take the faster close? My vote is the faster close.'],
            ['related_table' => 'deals', 'related_id' => 14, 'author' => 1, 'body' => 'Agreed — closing speed matters more here. Drafting the counter now.'],
            ['related_table' => 'deals', 'related_id' => 20, 'author' => 0, 'body' => 'Closed Won. Integration scope signed off, handing over to delivery on Monday.'],
            ['related_table' => 'deals', 'related_id' => 20, 'author' => 2, 'body' => 'Handover received, kickoff scheduled. Nice one.'],
            ['related_table' => 'deals', 'related_id' => 26, 'author' => 1, 'body' => 'Phase 2 depends on Phase 1 sign-off — do not send the proposal until the migration deal is Won.'],
            ['related_table' => 'deals', 'related_id' => 26, 'author' => 0, 'body' => 'Understood, holding the proposal until then.'],
            ['related_table' => 'companies', 'related_id' => 7, 'author' => 2, 'body' => 'DataStream reorganised their procurement team — new contact is coming through next week.'],
            ['related_table' => 'companies', 'related_id' => 7, 'author' => 0, 'body' => 'Thanks, I will update the contact list once we have the name.'],
            ['related_table' => 'companies', 'related_id' => 2, 'author' => 1, 'body' => 'Global Solutions is up for renewal in Q4 — start the account review early this time.'],
            ['related_table' => 'contacts', 'related_id' => 4, 'author' => 1, 'body' => 'Emma is our main sponsor at TechVision, loop her in on anything touching the migration.'],
            ['related_table' => 'contacts', 'related_id' => 3, 'author' => 0, 'body' => 'Michael is only reachable on Tuesdays and Thursdays — avoid Monday calls.'],
            ['related_table' => 'leads', 'related_id' => 2, 'author' => 2, 'body' => 'Marcus from Apex Logistics asked for pricing on the mid-tier package. Passing to sales.'],
            ['related_table' => 'leads', 'related_id' => 6, 'author' => 0, 'body' => 'Marked as Lost — they went with an in-house solution. Worth re-approaching in six months.'],
        ],
        // Demo notes — private "My Notes" entries seeded per demo user, some linked
        // to a CRM record and some standalone (related_table/related_id null). Two
        // carry a reminder_date (computed at install time) to demonstrate the
        // cron_notifications.php note-reminder flow firing a real notification.
        'demo_notes' => [
            ['author' => 0, 'related_table' => 'deals', 'related_id' => 1, 'body' => 'Follow up with John Smith about the annual plan pricing.', 'reminder_date' => date('Y-m-d', strtotime('+1 day'))],
            ['author' => 0, 'related_table' => 'deals', 'related_id' => 4, 'body' => 'Send the Support & Maintenance renewal confirmation.', 'reminder_date' => null],
            ['author' => 0, 'related_table' => null, 'related_id' => null, 'body' => 'Prepare Q3 pipeline summary for the team meeting.', 'reminder_date' => null],
            ['author' => 1, 'related_table' => 'deals', 'related_id' => 2, 'body' => 'Check discount tier before Anna sends updated pricing.', 'reminder_date' => date('Y-m-d')],
            ['author' => 1, 'related_table' => 'companies', 'related_id' => 1, 'body' => 'Acme renewal is coming up — review their support history first.', 'reminder_date' => null],
            ['author' => 1, 'related_table' => null, 'related_id' => null, 'body' => "Review this month's Won deals for commission calculation.", 'reminder_date' => null],
            ['author' => 2, 'related_table' => 'companies', 'related_id' => 1, 'body' => 'Acme tickets are high priority — check queue every morning.', 'reminder_date' => null],
            ['author' => 2, 'related_table' => 'deals', 'related_id' => 7, 'body' => 'Schedule a check-in call about the infrastructure buildout timeline.', 'reminder_date' => null],
        ],
        // Demo files — small CSV attachments on CRM records, written both to disk
        // (storage_path convention mirrors public/api/files.php's upload handler) and
        // to spw_files. 'author' indexes into demo_users above (uploaded_by).
        'demo_files' => [
            [
                'related_table' => 'deals', 'related_id' => 1, 'author' => 0,
                'filename' => 'enterprise-license-pricing.csv',
                'description' => 'Pricing breakdown for the Enterprise License Q2 deal.',
                'content' => "Item,Qty,Unit Price,Total\nEnterprise License (Annual),1,45000.00,45000.00\nOnboarding Package,1,0.00,0.00\n",
            ],
            [
                'related_table' => 'deals', 'related_id' => 2, 'author' => 1,
                'filename' => 'digital-transformation-scope.csv',
                'description' => 'Scope items discussed during negotiation.',
                'content' => "Phase,Description,Est. Hours\nDiscovery,Stakeholder interviews and audit,80\nImplementation,Core platform rollout,320\nTraining,End-user onboarding,40\n",
            ],
            [
                'related_table' => 'companies', 'related_id' => 1, 'author' => 1,
                'filename' => 'acme-account-summary.csv',
                'description' => 'Quarterly account summary for Acme Corporation.',
                'content' => "Metric,Value\nOpen Deals,2\nTotal Pipeline Value,70000\nSupport Tickets (Open),3\n",
            ],
            [
                'related_table' => 'contacts', 'related_id' => 1, 'author' => 0,
                'filename' => 'john-smith-notes.csv',
                'description' => 'Call log summary for John Smith.',
                'content' => "Date,Channel,Summary\n2026-06-01,Email,Sent updated proposal\n2026-06-05,Call,Discussed contract terms\n",
            ],
        ],
        // Demo images — record image gallery for deals. 'source_file' names a PNG in
        // admin/demo/assets/images/ (copies of the app's own assets/icons artwork, so the
        // demo ships no third-party media); seed.php copies it into storage/files/ and
        // tags the spw_files row with IMAGES_FIELD. Contacts used to carry photographs of
        // faces here, which were dropped for licensing/likeness reasons — hence icons.
        // 'author' indexes into demo_users above (uploaded_by). Keep at most
        // max_per_record (5) entries per deal, matching the 'images' config above.
        'demo_images' => [
            ['related_table' => 'deals', 'related_id' => 1, 'author' => 0, 'source_file' => 'docs.png',           'display_name' => 'Signed Contract Scan'],
            ['related_table' => 'deals', 'related_id' => 1, 'author' => 1, 'source_file' => 'fact_check.png',     'display_name' => 'Approved Terms Sheet'],
            ['related_table' => 'deals', 'related_id' => 2, 'author' => 1, 'source_file' => 'account_tree.png',   'display_name' => 'Solution Architecture'],
            ['related_table' => 'deals', 'related_id' => 2, 'author' => 0, 'source_file' => 'calendar_check.png', 'display_name' => 'Rollout Plan'],
            ['related_table' => 'deals', 'related_id' => 3, 'author' => 1, 'source_file' => 'database.png',       'display_name' => 'Source System Inventory'],
            ['related_table' => 'deals', 'related_id' => 4, 'author' => 0, 'source_file' => 'order_approve.png',  'display_name' => 'Signed Order Form'],
            ['related_table' => 'deals', 'related_id' => 7, 'author' => 2, 'source_file' => 'warehouse.png',      'display_name' => 'Data Centre Site Photo'],
        ],
        // Demo record ownership ("My records" panel) — assigns a few CRM records to
        // demo users so the panel isn't empty right after install. 'author' indexes
        // into demo_users above and becomes the owner_id.
        'demo_record_owners' => [
            ['related_table' => 'deals',      'related_id' => 1, 'author' => 0],
            ['related_table' => 'deals',      'related_id' => 4, 'author' => 0],
            ['related_table' => 'contacts',   'related_id' => 1, 'author' => 0],
            ['related_table' => 'deals',      'related_id' => 2, 'author' => 1],
            ['related_table' => 'companies',  'related_id' => 1, 'author' => 1],
            ['related_table' => 'deals',      'related_id' => 7, 'author' => 2],
        ],
        // Demo notifications — a few pre-seeded bell-icon entries per demo user so
        // the notification panel isn't empty right after install. 'author' indexes
        // into demo_users above and becomes the recipient (user_id).
        'demo_notifications' => [
            ['author' => 0, 'title' => 'New comment on deal: Enterprise License Q2', 'related_table' => 'deals', 'related_id' => 1, 'is_read' => false],
            ['author' => 1, 'title' => 'New comment on deal: Digital Transformation Project', 'related_table' => 'deals', 'related_id' => 2, 'is_read' => false],
            ['author' => 2, 'title' => 'New comment on deal: Cloud Infrastructure Buildout', 'related_table' => 'deals', 'related_id' => 7, 'is_read' => false],
            ['author' => 0, 'title' => 'You were assigned as owner of: Support & Maintenance', 'related_table' => 'deals', 'related_id' => 4, 'is_read' => true],
            ['author' => 1, 'title' => 'You were assigned as owner of: Acme Corporation', 'related_table' => 'companies', 'related_id' => 1, 'is_read' => true],
            // One notification per new comment thread above, addressed to a demo user
            // other than the thread's last commenter. The unique key on spw_notifications
            // is (user_id, source_table, source_id, notify_date) — keep these pairs distinct.
            ['author' => 0, 'title' => 'New comment on deal: Cloud Migration Services', 'related_table' => 'deals', 'related_id' => 3, 'is_read' => false],
            ['author' => 0, 'title' => 'New comment on deal: Data Analytics Platform', 'related_table' => 'deals', 'related_id' => 8, 'is_read' => false],
            ['author' => 0, 'title' => 'New comment on deal: Growth Fund Round A', 'related_table' => 'deals', 'related_id' => 14, 'is_read' => false],
            ['author' => 1, 'title' => 'New comment on deal: System Integration & APIs', 'related_table' => 'deals', 'related_id' => 20, 'is_read' => true],
            ['author' => 1, 'title' => 'New comment on deal: Infrastructure Expansion Phase 2', 'related_table' => 'deals', 'related_id' => 26, 'is_read' => false],
            ['author' => 2, 'title' => 'New comment on company: DataStream Analytics', 'related_table' => 'companies', 'related_id' => 7, 'is_read' => false],
            ['author' => 0, 'title' => 'New comment on company: Global Solutions Ltd', 'related_table' => 'companies', 'related_id' => 2, 'is_read' => true],
            ['author' => 2, 'title' => 'New comment on contact: Emma Wilson', 'related_table' => 'contacts', 'related_id' => 4, 'is_read' => false],
            ['author' => 1, 'title' => 'New comment on contact: Michael Brown', 'related_table' => 'contacts', 'related_id' => 3, 'is_read' => true],
            ['author' => 0, 'title' => 'New comment on lead: Marcus Bennett', 'related_table' => 'leads', 'related_id' => 2, 'is_read' => false],
            ['author' => 2, 'title' => 'New comment on lead: Lucas Müller', 'related_table' => 'leads', 'related_id' => 6, 'is_read' => true],
        ],
        'menu_items' => [
            ['key' => 'dashboard'],
            ['key' => 'calendar'],
            ['key' => 'companies', 'children' => [
                ['key' => 'contacts'],
            ]],
            ['key' => 'deals'],
            ['key' => 'activities'],
            ['key' => 'board'],
            ['key' => 'files'],
        ],
        'files_relations' => [
            ['table' => 'companies', 'col1' => 'name',       'col2' => ''],
            ['table' => 'deals',     'col1' => 'title',      'col2' => ''],
            ['table' => 'contacts',  'col1' => 'first_name', 'col2' => 'last_name'],
        ],
        'automations' => [
            [
                'id'            => 'auto_demo_crm_001',
                'name'          => 'Deal Won — Close Notification',
                'enabled'       => true,
                'trigger_table' => 'deals',
                'trigger_event' => 'update',
                'conditions'    => [
                    'type'  => 'AND',
                    'rules' => [
                        ['field' => 'stage', 'operator' => '=', 'value' => 'Won'],
                    ],
                ],
                'actions' => [
                    [
                        'type'     => 'notify',
                        'user_ids' => ['{{ current_user.id }}'],
                        'title'    => 'Deal won: {{ record.title }}',
                        'link'     => 'edit.php?table=deals&id={{ record.id }}',
                    ],
                ],
            ],
            [
                'id'            => 'auto_demo_crm_002',
                'name'          => 'New Web Lead — Auto Contact',
                'enabled'       => true,
                'trigger_table' => 'leads',
                'trigger_event' => 'create',
                'conditions'    => [
                    'type'  => 'AND',
                    'rules' => [
                        ['field' => 'source', 'operator' => '=', 'value' => 'Web'],
                    ],
                ],
                'actions' => [
                    [
                        'type' => 'update',
                        'set'  => ['status' => 'Contacted'],
                    ],
                ],
            ],
            [
                'id'            => 'auto_demo_crm_004',
                'name'          => 'New Lead — Welcome Email',
                'enabled'       => true,
                'trigger_table' => 'leads',
                'trigger_event' => 'create',
                'conditions'    => [
                    'type'  => 'AND',
                    'rules' => [
                        ['field' => 'email', 'operator' => 'is_not_empty', 'value' => ''],
                    ],
                ],
                // Email action (2.9): queues to spw_automation_emails, delivered by cron_notifications.php
                'actions' => [
                    [
                        'type'       => 'email',
                        'recipients' => ['{{ record.email }}'],
                        'subject'    => 'Thank you for your interest, {{ record.first_name }}',
                        'body'       => "Hello {{ record.first_name }} {{ record.last_name }},\n\nThank you for reaching out to us. One of our sales representatives will contact you shortly.\n\nBest regards,\nThe Sales Team",
                    ],
                ],
            ],
        ],
        // Data Anonymization (2.9) — GDPR retention rules for lead PII. Frequency
        // 'manual' so nothing runs unattended; use Anonymization > Preview (dry run)
        // to see matches (seed includes stale leads older than 1 year).
        // No 'dictionary' key here on purpose: it would only repeat the module's own
        // defaults (includes/admin/anonymization.php). seed.php skips an empty
        // dictionary, so anonymization_load merges those defaults in instead.
        'anonymization' => [
            'enabled'    => true,
            'frequency'  => 'manual',
            'rules'      => [
                ['table' => 'leads', 'date_column' => 'created_at', 'days' => 365, 'column' => 'email',      'replacement' => 'anonymized@example.com'],
                ['table' => 'leads', 'date_column' => 'created_at', 'days' => 365, 'column' => 'phone',      'replacement' => '[REDACTED]'],
                ['table' => 'leads', 'date_column' => 'created_at', 'days' => 365, 'column' => 'first_name', 'replacement' => '[REDACTED]'],
                ['table' => 'leads', 'date_column' => 'created_at', 'days' => 365, 'column' => 'last_name',  'replacement' => '[REDACTED]'],
            ],
        ],
        // RAG knowledge base — the sample documents describing this demo, loaded into
        // spw_rag_files so the "Ask AI" panel has something to retrieve straight after
        // install. Content is read from docs/rag-samples/ at install time rather than
        // duplicated here, so the docs stay single-sourced; 'file' is resolved against
        // that directory only (see seed.php, which rejects anything with a separator).
        // Ingest is offline: retrieval is PostgreSQL full-text search, so no Ollama call
        // happens here — Ollama is only needed later, to answer a question.
        // Tags mirror the table in docs/rag-samples/README.md.
        'rag_docs' => [
            ['file' => 'crm_overview.txt',           'tag' => 'crm'],
            ['file' => 'crm_companies_contacts.txt', 'tag' => 'companies'],
            ['file' => 'crm_deals.txt',              'tag' => 'deals'],
            ['file' => 'crm_activities.txt',         'tag' => 'activities'],
            ['file' => 'crm_leads.txt',              'tag' => 'leads'],
            ['file' => 'crm_workflows.txt',          'tag' => 'workflows'],
            ['file' => 'crm_dashboard_calendar.txt', 'tag' => 'dashboard'],
            ['file' => 'crm_reports_print.txt',      'tag' => 'reports'],
            ['file' => 'crm_collaboration.txt',      'tag' => 'collaboration'],
        ],
    ];
}
