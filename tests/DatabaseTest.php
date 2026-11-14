<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Segara\Storage\Database;
use Tests\Support\TestCase;

final class DatabaseTest extends TestCase
{
    public function testConnectionUsesSecurePdoAndSqliteDefaults(): void
    {
        $pdo = Database::connect($this->sqlitePath);

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame(1, (int) $this->query($pdo, 'PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(5000, (int) $this->query($pdo, 'PRAGMA busy_timeout')->fetchColumn());
        self::assertSame('delete', strtolower((string) $this->query($pdo, 'PRAGMA journal_mode')->fetchColumn()));
    }

    public function testWalIsUsedOnlyWhenExplicitlyConfigured(): void
    {
        $pdo = Database::connect($this->sqlitePath, 'WAL');

        self::assertSame('wal', strtolower((string) $this->query($pdo, 'PRAGMA journal_mode')->fetchColumn()));
    }

    public function testUnknownJournalModeIsRejectedBeforeCreatingDatabase(): void
    {
        try {
            Database::connect($this->sqlitePath, 'MEMORY');
            self::fail('An unsupported journal mode was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('journal', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->sqlitePath);
    }

    public function testConnectingDoesNotMigrateOrRebuildDatabase(): void
    {
        $pdo = Database::connect($this->sqlitePath);

        $tables = $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll();
        self::assertSame([], $tables);
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement;
    }
}
