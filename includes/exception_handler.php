<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Exception\HttpException;
use App\Exception\RedirectException;
use App\Exception\ResponseException;
use App\Exception\ServerErrorException;

function os_response_mode(?string $mode = null): string
{
    static $current = 'html';

    if ($mode !== null) {
        $current = $mode;
    }
    if ($current === 'html' && PHP_SAPI === 'cli') {
        return 'cli';
    }
    return $current;
}

function os_register_exception_handler(string $mode = 'html'): void
{
    static $registered = false;

    os_response_mode($mode);
    if ($registered) {
        return;
    }
    $registered = true;
    set_exception_handler('os_handle_exception');
}

function os_handle_exception(Throwable $exception): void
{
    if ($exception instanceof RedirectException) {
        os_emit_redirect($exception);
        return;
    }
    if ($exception instanceof ResponseException) {
        os_emit_response($exception);
        return;
    }
    if (!$exception instanceof HttpException) {
        error_log(sprintf(
            '[unhandled] %s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
        $exception = new ServerErrorException('Internal server error.');
    }
    os_emit_http_error($exception);
}

function os_emit_redirect(RedirectException $redirect): void
{
    if (os_response_mode() === 'cli') {
        return;
    }
    if (!headers_sent()) {
        header('Location: ' . $redirect->url(), true, $redirect->statusCode());
    }
}

function os_emit_response(ResponseException $response): void
{
    $payload = $response->payload();
    if ($payload === null) {
        return;
    }
    if (!headers_sent()) {
        if ($response->statusCode() > 0) {
            http_response_code($response->statusCode());
        }
        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    echo $payload;
}

function os_emit_http_error(HttpException $error): void
{
    if (os_response_mode() === 'cli') {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        return;
    }

    $isJson = os_response_mode() === 'json' || $error->hasBody();

    if (!headers_sent()) {
        if ($error->statusCode() > 0) {
            http_response_code($error->statusCode());
        }
        header('Content-Type: ' . ($isJson ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8'));
    }

    if ($isJson) {
        echo json_encode($error->body());
        return;
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . $error->statusCode() . '</title></head><body><h1>'
        . htmlspecialchars((string) $error->statusCode(), ENT_QUOTES, 'UTF-8')
        . '</h1><p>' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</p></body></html>';
}
