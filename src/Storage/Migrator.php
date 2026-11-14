<?php

declare(strict_types=1);

namespace Segara\Storage;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDirectory,
    ) {
    }

    /** @return list<int> Versions applied by this run. */
    public function migrate(): array
    {
        $this->createMigrationTable();
        $appliedVersions = $this->appliedVersions();
        $applied = [];

        foreach ($this->migrationFiles() as $version => $file) {
            if (isset($appliedVersions[$version])) {
                continue;
            }

            $sql = file_get_contents($file['path']);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration ' . $file['name'] . '.');
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, name, applied_at) VALUES (:version, :name, :applied_at)'
                );
                $statement->execute([
                    'version' => $version,
                    'name' => $file['name'],
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $this->pdo->commit();
                $applied[] = $version;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $applied;
    }

    private function createMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version INTEGER PRIMARY KEY, '
            . 'name TEXT NOT NULL UNIQUE, '
            . 'applied_at TEXT NOT NULL'
            . ')'
        );
    }

    /** @return array<int, true> */
    private function appliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM schema_migrations');
        if ($statement === false) {
            throw new RuntimeException('Unable to read applied migrations.');
        }
        $versions = $statement->fetchAll(PDO::FETCH_COLUMN);

        $applied = [];
        foreach ($versions as $version) {
            if (!is_int($version) && !is_string($version)) {
                throw new RuntimeException('Applied migration version has an invalid type.');
            }
            $applied[(int) $version] = true;
        }

        return $applied;
    }

    /** @return array<int, array{name: string, path: string}> */
    private function migrationFiles(): array
    {
        $directory = realpath($this->migrationsDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new InvalidArgumentException('Migration directory is missing or unreadable.');
        }

        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to list migration files.');
        }

        $migrations = [];
        foreach ($entries as $entry) {
            if (preg_match('/^(\d+)_[A-Za-z0-9_-]+\.sql$/', $entry, $matches) !== 1) {
                continue;
            }

            $version = (int) $matches[1];
            if ($version < 1 || isset($migrations[$version])) {
                throw new RuntimeException('Migration versions must be unique positive integers.');
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Migration file is not readable: ' . $entry . '.');
            }
            $migrations[$version] = ['name' => $entry, 'path' => $path];
        }

        ksort($migrations, SORT_NUMERIC);
        return $migrations;
    }
}
