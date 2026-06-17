<?php

declare(strict_types=1);

// admin/demo/tasks.php — Tasks/project-management demo app definition (data only, no auth/routing)
// demo_def_tasks($conn): returns the spw_tasks schema spec — DDL, view names (summary, workload, milestone progress, time report), and seed data
// Consumed by demo/seed.php during demo_install

function demo_def_tasks($conn): array
{
    return [
        'pg_schema'  => 'spw_tasks',
        'view_names' => [
            'v_demo_tasks_summary',
            'v_demo_tasks_workload',
            'v_demo_tasks_milestone_progress',
            'v_demo_tasks_time_report',
        ],
        'ddl' => [
            'CREATE SCHEMA IF NOT EXISTS spw_tasks',
            "CREATE TABLE IF NOT EXISTS spw_tasks.projects (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, status VARCHAR(50) DEFAULT 'Active', priority VARCHAR(50) DEFAULT 'Medium', due_date DATE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_tasks.team_members (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, role VARCHAR(100), email VARCHAR(255), capacity_hours_per_week NUMERIC(5,2) DEFAULT 40, active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_tasks.milestones (id SERIAL PRIMARY KEY, project_id INTEGER REFERENCES spw_tasks.projects(id) ON DELETE CASCADE, name VARCHAR(255) NOT NULL, description TEXT, due_date DATE, status VARCHAR(50) DEFAULT 'Planned', created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_tasks.tasks (id SERIAL PRIMARY KEY, project_id INTEGER REFERENCES spw_tasks.projects(id) ON DELETE CASCADE, milestone_id INTEGER REFERENCES spw_tasks.milestones(id) ON DELETE SET NULL, title VARCHAR(255) NOT NULL, description TEXT, status VARCHAR(50) DEFAULT 'Todo', priority VARCHAR(50) DEFAULT 'Medium', assignee_id INTEGER REFERENCES spw_tasks.team_members(id) ON DELETE SET NULL, estimated_hours NUMERIC(5,2), due_date DATE, created_at TIMESTAMP DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS spw_tasks.time_logs (id SERIAL PRIMARY KEY, task_id INTEGER REFERENCES spw_tasks.tasks(id) ON DELETE CASCADE, hours NUMERIC(5,2) NOT NULL, description VARCHAR(255), logged_at TIMESTAMP DEFAULT NOW())",
            'CREATE OR REPLACE VIEW spw_tasks.v_demo_tasks_summary AS SELECT p.name AS project, t.status, COUNT(*) AS task_count FROM spw_tasks.tasks t JOIN spw_tasks.projects p ON p.id = t.project_id GROUP BY p.name, t.status ORDER BY p.name, t.status',
            "CREATE OR REPLACE VIEW spw_tasks.v_demo_tasks_workload AS SELECT tm.name AS member, tm.role, tm.capacity_hours_per_week AS capacity_h, COUNT(DISTINCT t.id) AS task_count, COALESCE(SUM(tl.hours), 0) AS logged_hours, COALESCE(SUM(t.estimated_hours), 0) AS estimated_hours FROM spw_tasks.team_members tm LEFT JOIN spw_tasks.tasks t ON t.assignee_id = tm.id AND t.status != 'Done' LEFT JOIN spw_tasks.time_logs tl ON tl.task_id = t.id WHERE tm.active = TRUE GROUP BY tm.id, tm.name, tm.role, tm.capacity_hours_per_week ORDER BY logged_hours DESC",
            'CREATE OR REPLACE VIEW spw_tasks.v_demo_tasks_milestone_progress AS SELECT p.name AS project, m.name AS milestone, m.due_date, m.status, COUNT(t.id) AS total_tasks, COUNT(CASE WHEN t.status = \'Done\' THEN 1 END) AS done_tasks, ROUND(100.0 * COUNT(CASE WHEN t.status = \'Done\' THEN 1 END) / NULLIF(COUNT(t.id), 0), 1) AS pct_done FROM spw_tasks.milestones m JOIN spw_tasks.projects p ON p.id = m.project_id LEFT JOIN spw_tasks.tasks t ON t.milestone_id = m.id GROUP BY m.id, p.name, m.name, m.due_date, m.status ORDER BY m.due_date',
            "CREATE OR REPLACE VIEW spw_tasks.v_demo_tasks_time_report AS SELECT COALESCE(tm.name, 'Unassigned') AS member, p.name AS project, COALESCE(SUM(t.estimated_hours), 0) AS estimated_h, COALESCE(SUM(tl.hours), 0) AS logged_h, ROUND(COALESCE(SUM(tl.hours), 0) - COALESCE(SUM(t.estimated_hours), 0), 2) AS variance FROM spw_tasks.time_logs tl JOIN spw_tasks.tasks t ON t.id = tl.task_id JOIN spw_tasks.projects p ON p.id = t.project_id LEFT JOIN spw_tasks.team_members tm ON tm.id = t.assignee_id GROUP BY tm.name, p.name ORDER BY tm.name, p.name",
        ],
        'seed_data' => [
            "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Website Redesign', 'Complete overhaul of corporate website', 'Active', 'High', NOW() + INTERVAL '75 days')",
            "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Mobile App Launch', 'Native iOS and Android applications', 'Active', 'Critical', NOW() + INTERVAL '32 days')",
            "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Cloud Migration', 'Move infrastructure to AWS', 'On Hold', 'High', NOW() + INTERVAL '108 days')",
            "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('API Documentation', 'Comprehensive REST API documentation', 'Active', 'Medium', NOW() + INTERVAL '47 days')",
            "INSERT INTO spw_tasks.projects (name, description, status, priority, due_date) VALUES ('Security Audit', 'Third-party security assessment', 'Completed', 'Critical', NOW() - INTERVAL '14 days')",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Alice', 'Frontend Developer', 'alice@example.com', 40)",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Bob', 'Backend Developer', 'bob@example.com', 40)",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Charlie', 'iOS Developer', 'charlie@example.com', 40)",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Diana', 'Android Developer', 'diana@example.com', 40)",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Frank', 'Technical Writer', 'frank@example.com', 32)",
            "INSERT INTO spw_tasks.team_members (name, role, email, capacity_hours_per_week) VALUES ('Grace', 'Security Engineer', 'grace@example.com', 40)",
            "INSERT INTO spw_tasks.milestones (project_id, name, description, due_date, status) VALUES (1, 'Design Phase', 'UI/UX design and mockups', NOW() + INTERVAL '20 days', 'Active')",
            "INSERT INTO spw_tasks.milestones (project_id, name, description, due_date, status) VALUES (1, 'Development Phase', 'Frontend implementation', NOW() + INTERVAL '60 days', 'Planned')",
            "INSERT INTO spw_tasks.milestones (project_id, name, description, due_date, status) VALUES (2, 'Alpha Release', 'Internal testing build', NOW() + INTERVAL '20 days', 'Active')",
            "INSERT INTO spw_tasks.milestones (project_id, name, description, due_date, status) VALUES (2, 'Beta Testing', 'External beta program', NOW() + INTERVAL '30 days', 'Planned')",
            "INSERT INTO spw_tasks.milestones (project_id, name, description, due_date, status) VALUES (4, 'Initial Draft', 'First complete draft of API docs', NOW() - INTERVAL '5 days', 'Done')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (1, 1, 'Design mockups', 'Create Figma designs for homepage', 'In Progress', 'High', 1, 12.00, NOW() + INTERVAL '18 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (1, 2, 'Frontend development', 'Implement React components', 'Todo', 'High', 2, 40.00, NOW() + INTERVAL '32 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (2, 3, 'iOS app development', 'Build native iOS app', 'In Progress', 'Critical', 3, 60.00, NOW() + INTERVAL '18 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (2, 4, 'Android app development', 'Build native Android app', 'Review', 'Critical', 4, 55.00, NOW() + INTERVAL '27 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (3, NULL, 'Infrastructure planning', 'Plan AWS architecture', 'Todo', 'High', 2, 20.00, NOW() + INTERVAL '48 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (4, 5, 'Write API docs', 'Document all endpoints', 'Done', 'Medium', 5, 16.00, NOW() - INTERVAL '2 days')",
            "INSERT INTO spw_tasks.tasks (project_id, milestone_id, title, description, status, priority, assignee_id, estimated_hours, due_date) VALUES (5, NULL, 'Vulnerability fixes', 'Address identified issues', 'Done', 'Critical', 6, 20.00, NOW() - INTERVAL '15 days')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (1, 8.5, 'Completed home page and nav bar designs', NOW() - INTERVAL '1 day')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (1, 6.0, 'Created responsive design variations', NOW() - INTERVAL '3 days')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (3, 10.5, 'Set up iOS project structure and core modules', NOW() - INTERVAL '2 days')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (3, 8.0, 'Implemented authentication flow', NOW() + INTERVAL '1 day')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (4, 9.0, 'Testing and bug fixes', NOW() - INTERVAL '5 days')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (6, 12.0, 'Complete API documentation', NOW() - INTERVAL '4 days')",
            "INSERT INTO spw_tasks.time_logs (task_id, hours, description, logged_at) VALUES (7, 15.5, 'Security audit response and fixes', NOW() - INTERVAL '10 days')",
        ],
        'schema_tables' => [
            'team_members' => ['display_name' => 'Team', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/person.png', 'columns' => [
                'id'                      => ['type' => 'number',    'display_name' => 'ID',                 'description' => 'Unique member identifier'],
                'name'                    => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Full name of team member'],
                'role'                    => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Role',                  'description' => 'Job title or role'],
                'email'                   => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Email',                 'description' => 'Contact email address'],
                'capacity_hours_per_week' => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Weekly Capacity (h)',   'description' => 'Available working hours per week'],
                'active'                  => ['type' => 'boolean',   'show_in_grid' => true, 'display_name' => 'Active',                'description' => 'Whether member is currently active'],
                'created_at'              => ['type' => 'timestamp', 'readonly' => true,     'display_name' => 'Created At',            'description' => 'Date record was created'],
            ], 'subtables' => [
                ['table' => 'tasks', 'foreign_key' => 'assignee_id', 'label' => 'Assigned Tasks', 'columns_to_show' => ['title', 'status', 'priority', 'due_date']],
            ]],
            'projects' => ['display_name' => 'Projects', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/account_tree.png', 'columns' => [
                'id'          => ['type' => 'number',    'display_name' => 'ID',          'description' => 'Unique project identifier'],
                'name'        => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Project name or title'],
                'description' => ['type' => 'text',      'display_name' => 'Description', 'description' => 'Detailed project description'],
                'status'      => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Active', 'On Hold', 'Completed', 'Cancelled'], 'enum_colors' => ['Active' => '#6ee7b7', 'On Hold' => '#fcd34d', 'Completed' => '#93c5fd', 'Cancelled' => '#f87171'], 'display_name' => 'Status', 'description' => 'Current project status'],
                'priority'    => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#d1d5db', 'Medium' => '#fcd34d', 'High' => '#f87171', 'Critical' => '#c4b5fd'], 'display_name' => 'Priority', 'description' => 'Project priority level'],
                'due_date'    => ['type' => 'date',      'show_in_grid' => true, 'display_name' => 'Due Date',    'description' => 'Projected project completion date'],
                'created_at'  => ['type' => 'timestamp', 'readonly' => true,     'display_name' => 'Created At',  'description' => 'Date when project record was created'],
            ], 'subtables' => [
                ['table' => 'milestones', 'foreign_key' => 'project_id', 'label' => 'Milestones', 'columns_to_show' => ['name', 'status', 'due_date']],
                ['table' => 'tasks',      'foreign_key' => 'project_id', 'label' => 'Tasks',      'columns_to_show' => ['title', 'status', 'priority', 'assignee_id', 'due_date']],
            ]],
            'milestones' => ['display_name' => 'Milestones', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/calendar_check.png', 'columns' => [
                'id'          => ['type' => 'number',    'display_name' => 'ID',          'description' => 'Unique milestone identifier'],
                'project_id'  => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Project',     'description' => 'Project this milestone belongs to'],
                'name'        => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Name', 'description' => 'Milestone name or sprint title'],
                'description' => ['type' => 'text',      'display_name' => 'Description', 'description' => 'Milestone scope and goals'],
                'due_date'    => ['type' => 'date',      'show_in_grid' => true, 'display_name' => 'Due Date',    'description' => 'Target completion date'],
                'status'      => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Planned', 'Active', 'Done', 'Delayed'], 'enum_colors' => ['Planned' => '#d1d5db', 'Active' => '#93c5fd', 'Done' => '#6ee7b7', 'Delayed' => '#f87171'], 'display_name' => 'Status', 'description' => 'Current milestone status'],
                'created_at'  => ['type' => 'timestamp', 'readonly' => true,     'display_name' => 'Created At',  'description' => 'Date when milestone was created'],
            ], 'foreign_keys' => [
                'project_id' => ['reference_table' => 'projects', 'reference_column' => 'id', 'display_column' => 'name'],
            ], 'subtables' => [
                ['table' => 'tasks', 'foreign_key' => 'milestone_id', 'label' => 'Tasks', 'columns_to_show' => ['title', 'status', 'priority', 'assignee_id', 'due_date']],
            ]],
            'tasks' => ['display_name' => 'Tasks', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/checklist_rtl.png', 'columns' => [
                'id'              => ['type' => 'number',    'display_name' => 'ID',            'description' => 'Unique task identifier'],
                'project_id'      => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Project',       'description' => 'Project this task belongs to'],
                'milestone_id'    => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Milestone',     'description' => 'Sprint or milestone this task is part of'],
                'title'           => ['type' => 'text',      'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Title', 'description' => 'Task title or name'],
                'description'     => ['type' => 'text',      'display_name' => 'Description',   'description' => 'Detailed task description'],
                'status'          => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Todo', 'In Progress', 'Review', 'Done', 'Blocked'], 'enum_colors' => ['Todo' => '#d1d5db', 'In Progress' => '#93c5fd', 'Review' => '#fcd34d', 'Done' => '#6ee7b7', 'Blocked' => '#f87171'], 'display_name' => 'Status', 'description' => 'Current task status'],
                'priority'        => ['type' => 'enum',      'show_in_grid' => true, 'options' => ['Low', 'Medium', 'High', 'Critical'], 'enum_colors' => ['Low' => '#d1d5db', 'Medium' => '#fcd34d', 'High' => '#f87171', 'Critical' => '#c4b5fd'], 'display_name' => 'Priority', 'description' => 'Task priority level'],
                'assignee_id'     => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Assigned To',  'description' => 'Team member responsible for task'],
                'estimated_hours' => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Est. Hours',   'description' => 'Estimated effort in hours'],
                'due_date'        => ['type' => 'date',      'show_in_grid' => true, 'display_name' => 'Due Date',     'description' => 'Task completion deadline'],
                'created_at'      => ['type' => 'timestamp', 'readonly' => true,     'display_name' => 'Created At',   'description' => 'Date when task record was created'],
            ], 'foreign_keys' => [
                'project_id'   => ['reference_table' => 'projects',     'reference_column' => 'id', 'display_column' => 'name'],
                'milestone_id' => ['reference_table' => 'milestones',    'reference_column' => 'id', 'display_column' => 'name'],
                'assignee_id'  => ['reference_table' => 'team_members',  'reference_column' => 'id', 'display_column' => 'name'],
            ], 'subtables' => [
                ['table' => 'time_logs', 'foreign_key' => 'task_id', 'label' => 'Time Logs', 'columns_to_show' => ['hours', 'description', 'logged_at']],
            ]],
            'time_logs' => ['display_name' => 'Time Logs', 'schema' => 'spw_tasks', 'icon' => 'assets/icons/watch_screentime.png', 'columns' => [
                'id'          => ['type' => 'number',    'display_name' => 'ID',          'description' => 'Unique time log record identifier'],
                'task_id'     => ['type' => 'number',    'show_in_grid' => true, 'display_name' => 'Task',        'description' => 'Task this time log is for'],
                'hours'       => ['type' => 'number',    'show_in_grid' => true, 'not_null' => true, 'display_name' => 'Hours', 'description' => 'Hours spent on task'],
                'description' => ['type' => 'text',      'show_in_grid' => true, 'display_name' => 'Description', 'description' => 'Work description and notes'],
                'logged_at'   => ['type' => 'timestamp', 'readonly' => true,     'display_name' => 'Logged At',   'description' => 'Date when time was logged'],
            ], 'foreign_keys' => [
                'task_id' => ['reference_table' => 'tasks', 'reference_column' => 'id', 'display_column' => 'title'],
            ]],
        ],
        'dashboard_widgets' => [
            ['id' => 'demo_tasks_001', 'type' => 'stat_card', 'title' => 'Projects',         'table' => 'projects',     'width' => 1, 'height' => 1, 'query' => ['type' => 'count',    'column' => 'id',    'conditions' => []], 'icon' => 'assets/icons/account_tree.png',    'color' => '#289f6f', 'display_columns' => []],
            ['id' => 'demo_tasks_002', 'type' => 'stat_card', 'title' => 'Open Tasks',       'table' => 'tasks',        'width' => 1, 'height' => 1, 'query' => ['type' => 'count',    'column' => 'id',    'conditions' => []], 'icon' => 'assets/icons/checklist_rtl.png',   'color' => '#553eb1', 'display_columns' => []],
            ['id' => 'demo_tasks_006', 'type' => 'stat_card', 'title' => 'Team Members',     'table' => 'team_members', 'width' => 1, 'height' => 1, 'query' => ['type' => 'count',    'column' => 'id',    'conditions' => []], 'icon' => 'assets/icons/person.png',          'color' => '#2563eb', 'display_columns' => []],
            ['id' => 'demo_tasks_003', 'type' => 'pie_chart', 'title' => 'Task Status',      'table' => 'tasks',        'width' => 2, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'status',  'conditions' => []], 'icon' => 'assets/icons/checklist_rtl.png',   'color' => '#c4b5fd', 'display_columns' => []],
            ['id' => 'demo_tasks_004', 'type' => 'stat_card', 'title' => 'Total Hours',      'table' => 'time_logs',    'width' => 1, 'height' => 2, 'query' => ['type' => 'sum',      'column' => 'hours', 'conditions' => []], 'icon' => 'assets/icons/watch_screentime.png', 'color' => '#e2b932', 'display_columns' => []],
            ['id' => 'demo_tasks_007', 'type' => 'pie_chart', 'title' => 'Milestone Status', 'table' => 'milestones',   'width' => 1, 'height' => 2, 'query' => ['type' => 'group_by', 'group_column' => 'status',  'conditions' => []], 'icon' => 'assets/icons/calendar_check.png',  'color' => '#93c5fd', 'display_columns' => []],
            ['id' => 'demo_tasks_005', 'type' => 'list',      'title' => 'Overdue Tasks',    'table' => 'tasks',        'width' => 2, 'height' => 2, 'query' => [], 'icon' => 'assets/icons/fact_check.png', 'color' => '#d71919', 'display_columns' => ['title', 'project_id', 'assignee_id', 'due_date']],
        ],
        'calendar_sources' => [
            ['table' => 'projects',   'date_column' => 'due_date', 'title_column' => 'name',  'color' => '#6ee7b7', 'notify_before_days' => 3, 'url_template' => 'edit.php?table=projects&id={id}',   'icon' => 'assets/icons/account_tree.png',   'notified_users' => []],
            ['table' => 'milestones', 'date_column' => 'due_date', 'title_column' => 'name',  'color' => '#fcd34d', 'notify_before_days' => 2, 'url_template' => 'edit.php?table=milestones&id={id}', 'icon' => 'assets/icons/calendar_check.png', 'notified_users' => []],
            ['table' => 'tasks',      'date_column' => 'due_date', 'title_column' => 'title', 'color' => '#93c5fd', 'notify_before_days' => 1, 'url_template' => 'edit.php?table=tasks&id={id}',      'icon' => 'assets/icons/checklist_rtl.png',  'notified_users' => []],
        ],
        'workflows' => [
            ['id' => 'wf_demo_tasks_001', 'title' => 'New Project Setup', 'icon' => 'assets/icons/account_tree.png', 'description' => 'Create project → milestone → tasks → log time.', 'steps' => [
                ['title' => 'New Project',   'table' => 'projects',   'foreign_key' => '',              'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Milestone', 'table' => 'milestones', 'foreign_key' => 'project_id',   'link_to_step' => 0, 'allow_multiple' => true],
                ['title' => 'Add Tasks',     'table' => 'tasks',      'foreign_key' => 'milestone_id', 'link_to_step' => 1, 'allow_multiple' => true],
                ['title' => 'Log Time',      'table' => 'time_logs',  'foreign_key' => 'task_id',      'link_to_step' => 2, 'allow_multiple' => true],
            ]],
            ['id' => 'wf_demo_tasks_002', 'title' => 'Sprint Planning', 'icon' => 'assets/icons/calendar_check.png', 'description' => 'Select project → define sprint milestone → assign tasks.', 'steps' => [
                ['title' => 'Select Project', 'table' => 'projects',   'foreign_key' => '',              'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'New Sprint',     'table' => 'milestones', 'foreign_key' => 'project_id',   'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Add Tasks',      'table' => 'tasks',      'foreign_key' => 'milestone_id', 'link_to_step' => 1, 'allow_multiple' => true],
            ]],
            ['id' => 'wf_demo_tasks_003', 'title' => 'Assign & Track', 'icon' => 'assets/icons/person.png', 'description' => 'Select team member → assign tasks → log time.', 'steps' => [
                ['title' => 'Select Member', 'table' => 'team_members', 'foreign_key' => '',            'link_to_step' => 0, 'allow_multiple' => false],
                ['title' => 'Assign Task',   'table' => 'tasks',        'foreign_key' => 'assignee_id', 'link_to_step' => 0, 'allow_multiple' => true],
                ['title' => 'Log Time',      'table' => 'time_logs',    'foreign_key' => 'task_id',     'link_to_step' => 1, 'allow_multiple' => true],
            ]],
        ],
        'views' => [
            'v_demo_tasks_summary' => ['schema' => 'spw_tasks', 'display_name' => 'Task Summary', 'menu_name' => 'Summary', 'icon' => 'assets/icons/checklist_rtl.png', 'hidden' => false, 'description' => 'Task count by project & status — drill down from project to status breakdown.', 'columns' => [
                'project'    => ['display_name' => 'Project'],
                'status'     => ['display_name' => 'Status',  'aggregate' => ''],
                'task_count' => ['display_name' => 'Count',   'aggregate' => 'sum', 'summary' => 'sum'],
            ], 'drill_down' => ['enabled' => true, 'levels' => [
                ['group_by' => 'project', 'label' => 'Project'],
                ['group_by' => 'status',  'label' => 'Status'],
            ]]],
            'v_demo_tasks_workload' => ['schema' => 'spw_tasks', 'display_name' => 'Team Workload', 'menu_name' => 'Workload', 'icon' => 'assets/icons/person.png', 'hidden' => false, 'description' => 'Active tasks and logged hours per team member vs weekly capacity.', 'columns' => [
                'member'          => ['display_name' => 'Member'],
                'role'            => ['display_name' => 'Role'],
                'capacity_h'      => ['display_name' => 'Capacity (h/w)'],
                'task_count'      => ['display_name' => 'Active Tasks'],
                'logged_hours'    => ['display_name' => 'Logged Hours'],
                'estimated_hours' => ['display_name' => 'Est. Hours'],
            ], 'drill_down' => ['enabled' => false]],
            'v_demo_tasks_milestone_progress' => ['schema' => 'spw_tasks', 'display_name' => 'Milestone Progress', 'menu_name' => 'Milestones', 'icon' => 'assets/icons/calendar_check.png', 'hidden' => false, 'description' => 'Task completion progress per sprint and milestone.', 'columns' => [
                'project'     => ['display_name' => 'Project'],
                'milestone'   => ['display_name' => 'Milestone'],
                'due_date'    => ['display_name' => 'Due Date'],
                'status'      => ['display_name' => 'Status'],
                'total_tasks' => ['display_name' => 'Total Tasks'],
                'done_tasks'  => ['display_name' => 'Done'],
                'pct_done'    => ['display_name' => '% Done'],
            ], 'drill_down' => ['enabled' => false]],
            'v_demo_tasks_time_report' => ['schema' => 'spw_tasks', 'display_name' => 'Time Report', 'menu_name' => 'Time Report', 'icon' => 'assets/icons/watch_screentime.png', 'hidden' => false, 'description' => 'Logged vs estimated hours per member and project with budget variance.', 'columns' => [
                'member'      => ['display_name' => 'Member'],
                'project'     => ['display_name' => 'Project'],
                'estimated_h' => ['display_name' => 'Estimated (h)'],
                'logged_h'    => ['display_name' => 'Logged (h)'],
                'variance'    => ['display_name' => 'Variance (h)', 'color_rules' => [
                    ['condition' => '> 0', 'color' => '#f87171'],
                    ['condition' => '< 0', 'color' => '#6ee7b7'],
                ]],
            ], 'drill_down' => ['enabled' => false]],
        ],
        'menu_items' => [
            ['key' => 'team_members'],
            ['key' => 'projects', 'children' => [
                ['key' => 'milestones'],
                ['key' => 'tasks'],
            ]],
            ['key' => 'time_logs'],
        ],
        'files_relations' => [
            ['table' => 'projects',  'col1' => 'name', 'col2' => ''],
            ['table' => 'tasks',     'col1' => 'name', 'col2' => ''],
            ['table' => 'milestones','col1' => 'name', 'col2' => ''],
        ],
    ];
}
