<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Segara\Config;
use Segara\Http\ErrorHandler;
use Segara\Support\Logger;
use Tests\Support\TestCase;

final class ErrorHandlingTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = sys_get_temp_dir() . '/segara-logs-' . bin2hex(random_bytes(8));
        mkdir($this->logDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->logDir . '/error.log*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->logDir);
        parent::tearDown();
    }

    public function testLoggerRedactsSensitiveKeys(): void
    {
        $logger = new Logger($this->logDir);
        $logger->error('test-id', 'message', [
            'password' => 'hunter2',
            'auth_token' => 'tok123',
            'cookie' => 'PHPSESSID=abc',
            'Authorization' => 'Bearer xyz',
            'session_id' => 'sess1',
            'note' => 'visible',
            'nested' => ['token' => 'nested-secret'],
        ]);

        $log = $this->log();
        self::assertStringNotContainsString('hunter2', $log);
        self::assertStringNotContainsString('tok123', $log);
        self::assertStringNotContainsString('PHPSESSID=abc', $log);
        self::assertStringNotContainsString('Bearer xyz', $log);
        self::assertStringNotContainsString('sess1', $log);
        self::assertStringNotContainsString('nested-secret', $log);
        self::assertStringContainsString('[redacted]', $log);
        self::assertStringContainsString('visible', $log);
    }

    public function testLoggerRotatesAndBoundsFiles(): void
    {
        $logger = new Logger($this->logDir, 100);
        for ($i = 0; $i < 20; $i++) {
            $logger->error('id-' . $i, str_repeat('x', 100));
        }

        $files = glob($this->logDir . '/error.log*');
        self::assertNotFalse($files);
        self::assertLessThanOrEqual(5, count($files));
        self::assertFileExists($this->logDir . '/error.log');
        self::assertFileExists($this->logDir . '/error.log.1');
    }

    public function testHandlerReturnsGenericNonCacheable500(): void
    {
        $response = $this->handler()->handle(
            new RuntimeException('/var/www/segara/secret.php broke with token abc123')
        );

        self::assertSame(500, $response->status());
        self::assertStringContainsString('no-store', $response->headers()['Cache-Control'] ?? '');
        self::assertStringContainsString('Service unavailable', $response->body());
        self::assertStringNotContainsString('/var/www', $response->body());
        self::assertStringNotContainsString('secret.php', $response->body());
        self::assertStringNotContainsString('RuntimeException', $response->body());
        self::assertStringNotContainsString('abc123', $response->body());
    }

    public function testHandlerWritesRedactedLogWithReferenceId(): void
    {
        $handler = $this->handler();
        $response = $handler->handle(new RuntimeException('Something broke.'));

        preg_match('/<code>([0-9a-f]+)<\/code>/', $response->body(), $matches);
        $referenceId = $matches[1] ?? '';
        self::assertNotSame('', $referenceId);

        $log = $this->log();
        self::assertStringContainsString($referenceId, $log);
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString('Something broke.', $log);
    }

    private function handler(): ErrorHandler
    {
        return new ErrorHandler(
            new Logger($this->logDir),
            Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']),
        );
    }

    private function log(): string
    {
        $log = file_get_contents($this->logDir . '/error.log');
        self::assertNotFalse($log, 'Expected an error log to be written.');

        return $log;
    }
}
