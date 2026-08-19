<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

define('OPENSPARROW_CONFIG_LOADED', true);
define('APP_ENV', (string) getenv('APP_ENV'));
define('APP_URL', (string) getenv('APP_URL'));
define('STORAGE_PATH', (string) getenv('STORAGE_PATH'));
define('DB_HOST', (string) getenv('DB_HOST'));
define('DB_PORT', (string) getenv('DB_PORT'));
define('DB_CONNECT_TIMEOUT', (int) getenv('DB_CONNECT_TIMEOUT'));
define('DB_NAME', (string) getenv('DB_NAME'));
define('DB_USER', (string) getenv('DB_USER'));
define('DB_PASSWORD', (string) getenv('DB_PASSWORD'));
define('DB_SCHEMA', (string) getenv('DB_SCHEMA'));
define('DB_SSLMODE', (string) getenv('DB_SSLMODE'));
define('DB_SSLROOTCERT', (string) getenv('DB_SSLROOTCERT'));
define('APP_TIMEZONE', (string) getenv('APP_TIMEZONE'));
define('SECURE_COOKIES', getenv('SECURE_COOKIES') === 'true');
define('SESSION_SAMESITE', (string) getenv('SESSION_SAMESITE'));
define('SESSION_COOKIE_NAME', (string) getenv('SESSION_COOKIE_NAME'));
define('SESSION_MAX_LIFETIME', (int) getenv('SESSION_MAX_LIFETIME'));
define('SESSION_IDLE_TIMEOUT', (int) getenv('SESSION_IDLE_TIMEOUT'));
define('IP_HASH_SALT', (string) getenv('IP_HASH_SALT'));
define('APP_ENCRYPTION_KEY', (string) getenv('APP_ENCRYPTION_KEY'));
define('ARGON2_OPTIONS', [
    'memory_cost' => (int) getenv('ARGON2_MEMORY_COST'),
    'time_cost'   => (int) getenv('ARGON2_TIME_COST'),
    'threads'     => (int) getenv('ARGON2_THREADS'),
]);
define('PASSWORD_MIN_LENGTH', (int) getenv('PASSWORD_MIN_LENGTH'));
define('LOGIN_MAX_ATTEMPTS_PER_IP', (int) getenv('LOGIN_MAX_ATTEMPTS_PER_IP'));
define('LOGIN_MAX_ATTEMPTS_PER_USERNAME', (int) getenv('LOGIN_MAX_ATTEMPTS_PER_USERNAME'));
define('LOGIN_LOCKOUT_MINUTES', (int) getenv('LOGIN_LOCKOUT_MINUTES'));
define('LOGIN_RATE_LIMIT_WINDOW_MINUTES', (int) getenv('LOGIN_RATE_LIMIT_WINDOW_MINUTES'));
define('ADMIN_PURGE_MAX_DAYS', (int) getenv('ADMIN_PURGE_MAX_DAYS'));
define('CSP_REPORT_URI', (string) getenv('CSP_REPORT_URI'));
define('CSP_REPORT_ONLY', getenv('CSP_REPORT_ONLY') === 'true');
define('TRUST_PROXY_HEADERS', getenv('TRUST_PROXY_HEADERS') === 'true');
define('FORWARDED_HEADER_PRIORITY', explode(',', (string) getenv('FORWARDED_HEADER_PRIORITY')));
define('TRUSTED_PROXY_IPS', explode(',', (string) getenv('TRUSTED_PROXY_IPS')));
define('DEMO_MODE', getenv('DEMO_MODE') === 'true');
define('FILES_MAX_SIZE_MB', (int) getenv('FILES_MAX_SIZE_MB'));
define('FILES_PAGE_LIMIT', (int) getenv('FILES_PAGE_LIMIT'));
define('FILES_PAGE_LIMIT_MAX', (int) getenv('FILES_PAGE_LIMIT_MAX'));
define('THUMBNAIL_MAX_WIDTH', (int) getenv('THUMBNAIL_MAX_WIDTH'));
define('FILE_CACHE_MAX_AGE', (int) getenv('FILE_CACHE_MAX_AGE'));
define('THUMBNAIL_CACHE_MAX_AGE', (int) getenv('THUMBNAIL_CACHE_MAX_AGE'));
define('COMMENTS_PAGE_LIMIT_MAX', (int) getenv('COMMENTS_PAGE_LIMIT_MAX'));
define('COMMENTS_MINE_LIMIT', (int) getenv('COMMENTS_MINE_LIMIT'));
define('NOTIFICATIONS_DROPDOWN_LIMIT', (int) getenv('NOTIFICATIONS_DROPDOWN_LIMIT'));
define('AUTOMATION_EMAIL_FROM', (string) getenv('AUTOMATION_EMAIL_FROM'));
define('AUTOMATION_EMAIL_BATCH_LIMIT', (int) getenv('AUTOMATION_EMAIL_BATCH_LIMIT'));
define('AUTOMATION_EMAIL_MAX_ATTEMPTS', (int) getenv('AUTOMATION_EMAIL_MAX_ATTEMPTS'));
define('CONFIG_FILE_MAX_BYTES', (int) getenv('CONFIG_FILE_MAX_BYTES'));
define('MAX_LIST_ROWS', (int) getenv('MAX_LIST_ROWS'));
define('HSTS_MAX_AGE', (int) getenv('HSTS_MAX_AGE'));
define('HTTP_CLIENT_TIMEOUT', (int) getenv('HTTP_CLIENT_TIMEOUT'));
define('HTTP_CLIENT_CONNECT_TIMEOUT', (int) getenv('HTTP_CLIENT_CONNECT_TIMEOUT'));
define('RECORD_SNAPSHOTS_ENABLED', getenv('RECORD_SNAPSHOTS_ENABLED') === 'true');
define('CHAT_BUBBLE_ENABLED', getenv('CHAT_BUBBLE_ENABLED') === 'true');
define('OLLAMA_URL', (string) getenv('OLLAMA_URL'));
define('OLLAMA_MODEL', (string) getenv('OLLAMA_MODEL'));
define('API_RATE_LIMIT_PER_MIN', (int) getenv('API_RATE_LIMIT_PER_MIN'));
define('RAG_RATE_LIMIT_PER_MIN', (int) getenv('RAG_RATE_LIMIT_PER_MIN'));
define('RAG_MAX_CONCURRENT', (int) getenv('RAG_MAX_CONCURRENT'));
define('RAG_PAGE_CONTEXT_MAX_CHARS', (int) getenv('RAG_PAGE_CONTEXT_MAX_CHARS'));
