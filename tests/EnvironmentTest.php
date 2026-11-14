<?php

declare(strict_types=1);

namespace Tests;

use Segara\Environment;
use Tests\Support\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testIsolatedPathsAreUniqueWritableAndOutsideProjectRuntime(): void
    {
        $projectRoot = dirname(__DIR__);

        self::assertDirectoryExists($this->storePath);
        self::assertDirectoryExists($this->uploadsPath);
        self::assertDirectoryExists(dirname($this->configPath));
        self::assertIsWritable($this->storePath);
        self::assertIsWritable($this->uploadsPath);
        self::assertStringStartsWith(sys_get_temp_dir() . '/segara-tests/', $this->temporaryRoot);
        self::assertStringStartsWith($this->storePath . '/', $this->sqlitePath);
        self::assertFalse(str_starts_with($this->storePath, $projectRoot . '/store'));
        self::assertFalse(str_starts_with($this->uploadsPath, $projectRoot . '/uploads'));
        self::assertFalse(str_starts_with($this->configPath, $projectRoot . '/settings'));
    }

    public function testValidInjectedEnvironmentHasNoErrors(): void
    {
        $report = $this->environment()->report('8.4.1', ['PDO', 'pdo_sqlite'], $this->paths());

        self::assertTrue($report['ok']);
        self::assertSame([], $report['errors']);
    }

    public function testPhpVersionAndMissingExtensionsAreReportedWithoutChangingGlobals(): void
    {
        $before = get_loaded_extensions();
        $report = $this->environment()->report('8.4.0', ['PDO'], $this->paths());

        self::assertFalse($report['ok']);
        self::assertStringContainsString('PHP 8.4.1 or newer is required.', implode("\n", $report['errors']));
        self::assertStringContainsString('Missing PHP extension: pdo_sqlite.', implode("\n", $report['errors']));
        self::assertSame($before, get_loaded_extensions());
    }

    public function testPrivateStorageCannotBeInsidePublicRoot(): void
    {
        $paths = $this->paths();
        $paths['store'] = $paths['public'] . '/store';
        $paths['sqlite'] = $paths['store'] . '/segara.sqlite';

        $report = $this->environment()->report('8.4.1', ['PDO', 'pdo_sqlite'], $paths);

        self::assertFalse($report['ok']);
        self::assertStringContainsString('Private storage must be outside the public document root.', implode("\n", $report['errors']));
    }

    public function testMissingAndUnwritableRuntimePathsAreReported(): void
    {
        $paths = $this->paths();
        $paths['uploads'] = $this->temporaryRoot . '/missing-uploads';
        $paths['config'] = $this->temporaryRoot . '/missing-settings/config.php';

        $report = $this->environment()->report('8.4.1', ['PDO', 'pdo_sqlite'], $paths);

        self::assertFalse($report['ok']);
        self::assertStringContainsString('Runtime directory is missing or not writable: uploads.', implode("\n", $report['errors']));
        self::assertStringContainsString('Configuration path is not writable.', implode("\n", $report['errors']));
    }

    private function environment(): Environment
    {
        return new Environment(['pdo', 'pdo_sqlite']);
    }

    /** @return array{public: string, store: string, uploads: string, config: string, sqlite: string} */
    private function paths(): array
    {
        return [
            'public' => $this->temporaryRoot . '/public',
            'store' => $this->storePath,
            'uploads' => $this->uploadsPath,
            'config' => $this->configPath,
            'sqlite' => $this->sqlitePath,
        ];
    }
}
