<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $temporaryRoot;
    protected string $sqlitePath;
    protected string $uploadsPath;
    protected string $configPath;
    protected string $storePath;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/segara-tests';
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create the test temporary directory.');
        }

        $this->temporaryRoot = $base . '/' . bin2hex(random_bytes(16));
        $this->storePath = $this->temporaryRoot . '/store';
        $this->uploadsPath = $this->temporaryRoot . '/uploads';
        $settingsPath = $this->temporaryRoot . '/settings';
        $this->sqlitePath = $this->storePath . '/segara.sqlite';
        $this->configPath = $settingsPath . '/config.php';

        foreach ([$this->storePath, $this->uploadsPath, $settingsPath] as $path) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Unable to create isolated test paths.');
            }
        }
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryTree($this->temporaryRoot);
        parent::tearDown();
    }

    private function removeTemporaryTree(string $path): void
    {
        $prefix = sys_get_temp_dir() . '/segara-tests/';
        if (!str_starts_with($path, $prefix) || !is_dir($path) || is_link($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTemporaryTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
