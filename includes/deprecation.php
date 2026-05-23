<?php

// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

declare(strict_types=1);

if (!function_exists('deprecated_field')) {
    /**
     * Read a deprecated config key, log a warning, and return its value.
     * Returns null if the key does not exist.
     */
    function deprecated_field(array $config, string $key, string $replacement = ''): mixed
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $logDir  = __DIR__ . '/../storage/logs';
        $logFile = $logDir . '/deprecations.log';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $msg = date('Y-m-d H:i:s') . ' DEPRECATED field "' . $key . '"';
        if ($replacement !== '') {
            $msg .= ', use "' . $replacement . '" instead';
        }
        $msg .= "\n";

        @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);

        return $config[$key];
    }
}
