<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionHandlerTest extends TestCase
{
    private string $errorLogFile = '';

    private string $statusFile = '';

    protected function setUp(): void
    {
        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'os_handler_log_');
        $this->statusFile   = (string) tempnam(sys_get_temp_dir(), 'os_handler_status_');
    }

    protected function tearDown(): void
    {
        foreach ([$this->errorLogFile, $this->statusFile] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function fixture(): string
    {
        return __DIR__ . '/fixtures/throwing_request.php';
    }

    /**
     * @param array<string, string> $environment
     * @return array{status: int|false, output: string, errorLog: string}
     */
    private function runFixture(string $exception, string $mode = 'json', array $environment = []): array
    {
        $environment = array_merge([
            'OS_TEST_EXCEPTION'   => $exception,
            'OS_TEST_MODE'        => $mode,
            'OS_TEST_ERROR_LOG'   => $this->errorLogFile,
            'OS_TEST_STATUS_FILE' => $this->statusFile,
        ], $environment);

        $output = $this->execute([PHP_BINARY, $this->fixture()], $environment);
        $status = trim((string) file_get_contents($this->statusFile));

        return [
            'status'   => $status === 'false' ? false : (int) $status,
            'output'   => $output,
            'errorLog' => (string) file_get_contents($this->errorLogFile),
        ];
    }

    /**
     * @param array<string, string> $environment
     * @return array{headers: array<string, string>, body: string}
     */
    private function runFixtureThroughCgi(string $exception, string $mode = 'json'): array
    {
        $binary = $this->cgiBinary();
        if ($binary === null) {
            $this->markTestSkipped('php-cgi is not available, so response headers cannot be captured.');
        }

        $rawOutput = $this->execute([$binary], [
            'OS_TEST_EXCEPTION'   => $exception,
            'OS_TEST_MODE'        => $mode,
            'OS_TEST_ERROR_LOG'   => $this->errorLogFile,
            'OS_TEST_STATUS_FILE' => $this->statusFile,
            'SCRIPT_FILENAME'     => $this->fixture(),
            'REQUEST_METHOD'      => 'GET',
            'REDIRECT_STATUS'     => '1',
        ]);

        [$head, $body] = array_pad(preg_split('/\R\R/', $rawOutput, 2) ?: [], 2, '');

        $headers = [];
        foreach (preg_split('/\R/', (string) $head) ?: [] as $line) {
            if (str_contains((string) $line, ':')) {
                [$name, $value] = explode(':', (string) $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['headers' => $headers, 'body' => (string) $body];
    }

    private function cgiBinary(): ?string
    {
        $configured = (string) getenv('OS_TEST_PHP_CGI');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [dirname(PHP_BINARY) . '/php-cgi', dirname(PHP_BINARY) . '/php-cgi.exe', 'php-cgi'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $lookup    = ($isWindows ? 'where php-cgi 2>NUL' : 'command -v php-cgi 2>/dev/null');
        $located   = trim((string) strtok(trim((string) @shell_exec($lookup)), "\r\n"));

        return $located !== '' && is_file($located) ? $located : null;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function execute(array $command, array $environment): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($command, $descriptors, $pipes, $this->root(), $environment + $_ENV);

        $this->assertIsResource($process, 'Could not start the fixture process.');

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function httpExceptionProvider(): array
    {
        return [
            'ForbiddenException'  => ['forbidden', 403],
            'BadRequestException' => ['bad_request', 400],
            'NotFoundException'   => ['not_found', 404],
        ];
    }

    #[DataProvider('httpExceptionProvider')]
    public function testHttpExceptionsBecomeTheirOwnStatusCode(string $exception, int $expected): void
    {
        $result = $this->runFixture($exception);

        $this->assertSame(
            $expected,
            $result['status'],
            'An uncaught ' . $exception . ' must reach the handler and set its own status code.'
        );
    }

    public function testHttpExceptionMessageIsReturnedAsJsonInApiMode(): void
    {
        $result = $this->runFixture('forbidden');

        $this->assertSame('{"error":"Read-only access"}', $result['output']);
        $this->assertSame('', $result['errorLog'], 'A deliberate HTTP exception is not an error worth logging.');
    }

    public function testUnknownThrowableBecomes500AndIsLogged(): void
    {
        $result = $this->runFixture('unhandled');

        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('[unhandled] RuntimeException', $result['errorLog']);
        $this->assertStringContainsString('database connection lost', $result['errorLog']);
    }

    public function testUnknownThrowableNeverLeaksItsMessageToTheClient(): void
    {
        $result = $this->runFixture('unhandled');

        $this->assertStringNotContainsString('database connection lost', $result['output']);
        $this->assertSame('{"error":"Internal server error."}', $result['output']);
    }

    public function testRedirectExceptionSetsItsStatusCode(): void
    {
        $result = $this->runFixture('redirect');

        $this->assertSame(302, $result['status']);
        $this->assertSame('', $result['output'], 'A redirect must not emit a body.');
    }

    public function testRedirectExceptionSendsTheLocationHeader(): void
    {
        $response = $this->runFixtureThroughCgi('redirect');

        $this->assertSame('login.php', $response['headers']['location'] ?? null);
        $this->assertSame('302 Found', $response['headers']['status'] ?? null);
    }

    public function testHttpExceptionSendsAJsonContentTypeInApiMode(): void
    {
        $response = $this->runFixtureThroughCgi('forbidden');

        $this->assertSame('403 Forbidden', $response['headers']['status'] ?? null);
        $this->assertSame('application/json; charset=utf-8', $response['headers']['content-type'] ?? null);
        $this->assertSame('{"error":"Read-only access"}', $response['body']);
    }

    public function testHttpExceptionRendersAnHtmlPageInPageMode(): void
    {
        $response = $this->runFixtureThroughCgi('not_found', 'html');

        $this->assertSame('404 Not Found', $response['headers']['status'] ?? null);
        $this->assertStringStartsWith('text/html', $response['headers']['content-type'] ?? '');
        $this->assertStringContainsString('<h1>404</h1>', $response['body']);
        $this->assertStringContainsString('Record not found', $response['body']);
    }

    public function testResponseExceptionEmitsItsOwnPayloadAndHeaders(): void
    {
        $response = $this->runFixtureThroughCgi('response');

        $this->assertSame('201 Created', $response['headers']['status'] ?? null);
        $this->assertSame('application/json; charset=utf-8', $response['headers']['content-type'] ?? null);
        $this->assertSame('{"ok":true}', $response['body']);
    }

    public function testCliRunsReportOnStderrInsteadOfSendingHeaders(): void
    {
        $result = $this->runFixture('not_found', 'html');

        $this->assertFalse(
            $result['status'],
            'Under the CLI SAPI the handler must not set a response code, so cron output stays clean.'
        );
        $this->assertSame('', $result['output']);
    }

    public function testTheLatestRegistrationWins(): void
    {
        $result = $this->runFixture('not_found', 'html', ['OS_TEST_SWITCH_MODE' => 'json']);

        $this->assertSame(404, $result['status']);
        $this->assertSame(
            '{"error":"Record not found"}',
            $result['output'],
            'An API bootstrap after a page bootstrap must switch the response mode to JSON.'
        );
    }
}
