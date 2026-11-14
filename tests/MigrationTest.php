<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class MigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
    }

    public function testInitialMigrationCreatesSchemaAndSecondRunIsNoOp(): void
    {
        $migrator = $this->migrator();

        self::assertSame([1], $migrator->migrate());
        self::assertSame([], $migrator->migrate());

        $tables = $this->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'admins',
            'blocks',
            'media',
            'menu_items',
            'menus',
            'messages',
            'page_tags',
            'pages',
            'schema_migrations',
            'settings',
            'tags',
            'throttle',
        ], $tables);
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(
            '001_initial.sql',
            $this->query('SELECT name FROM schema_migrations WHERE version = 1')->fetchColumn()
        );
    }

    public function testUsefulQueryIndexesExist(): void
    {
        $this->migrator()->migrate();

        $indexes = $this->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('idx_pages_public', $indexes);
        self::assertContains('idx_pages_placement', $indexes);
        self::assertContains('idx_menu_items_order', $indexes);
        self::assertContains('idx_menu_items_parent', $indexes);
        self::assertContains('idx_blocks_order', $indexes);
        self::assertContains('idx_messages_unread', $indexes);
        self::assertContains('idx_throttle_expiry', $indexes);
    }

    #[DataProvider('invalidConstraintStatements')]
    public function testCheckConstraintsRejectInvalidValues(string $sql): void
    {
        $this->migrator()->migrate();

        $this->expectException(PDOException::class);
        $this->pdo->exec($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConstraintStatements(): iterable
    {
        $page = static fn (string $column, string $value): string => sprintf(
            "INSERT INTO pages (id, title, slug, body_markdown, description, author_name, created_at, updated_at, %s) VALUES ('p1', 'Title', 'slug-%s', '', '', 'Author', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', %s)",
            $column,
            $column,
            $value
        );

        yield 'page state' => [$page('state', "'invalid'")];
        yield 'schema type' => [$page('schema_type', "'Person'")];
        yield 'placement' => [$page('placement', "'sidebar'")];
        yield 'positive revision' => [$page('revision', '0')];
        yield 'contact flag' => [$page('show_contact_form', '2')];
        yield 'media decorative flag' => ["INSERT INTO media (id, original_name, stored_path, mime, size, alt_text, decorative, created_at, updated_at) VALUES ('m1', 'a.png', 'private/a.png', 'image/png', 1, '', 2, 'now', 'now')"];
        yield 'menu target type' => ["INSERT INTO menus (id, name, created_at, updated_at) VALUES ('menu', 'Main', 'now', 'now'); INSERT INTO menu_items (id, menu_id, target_type, label, position) VALUES ('item', 'menu', 'external', 'Home', 1)"];
        yield 'block location' => ["INSERT INTO blocks (id, location, position, type, settings_json) VALUES ('block', 'header', 1, 'menu', '{}')"];
        yield 'message read flag' => ["INSERT INTO messages (id, name, email, subject, body, is_read, created_at, updated_at) VALUES ('message', 'Name', 'n@example.test', 'Subject', 'Body', 2, 'now', 'now')"];
    }

    public function testForeignKeysAndCascadesAreEnforced(): void
    {
        $this->migrator()->migrate();

        $this->pdo->exec("INSERT INTO tags (id, name) VALUES ('tag', 'News')");
        try {
            $this->pdo->exec("INSERT INTO page_tags (page_id, tag_id) VALUES ('missing', 'tag')");
            self::fail('A tag was attached to a missing page.');
        } catch (PDOException) {
            self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM page_tags')->fetchColumn());
        }

        $this->pdo->exec("INSERT INTO pages (id, title, slug, body_markdown, description, state, schema_type, author_name, created_at, updated_at) VALUES ('page', 'Title', 'title', '', '', 'draft', 'WebPage', 'Author', 'now', 'now')");
        $this->pdo->exec("INSERT INTO page_tags (page_id, tag_id) VALUES ('page', 'tag')");
        $this->pdo->exec("INSERT INTO menus (id, name, created_at, updated_at) VALUES ('menu', 'Main', 'now', 'now')");
        $this->pdo->exec("INSERT INTO menu_items (id, menu_id, target_type, page_id, label, position) VALUES ('item', 'menu', 'page', 'page', 'Home', 1)");

        $this->pdo->exec("DELETE FROM pages WHERE id = 'page'");
        self::assertSame([], $this->query('SELECT page_id FROM page_tags')->fetchAll());
        self::assertNull($this->query("SELECT page_id FROM menu_items WHERE id = 'item'")->fetchColumn());

        $this->pdo->exec("DELETE FROM menus WHERE id = 'menu'");
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM menu_items')->fetchColumn());
    }

    public function testFailedMigrationRollsBackDdlAndMigrationRecord(): void
    {
        $directory = $this->temporaryRoot . '/migrations';
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/001_first.sql', 'CREATE TABLE first_probe (id INTEGER PRIMARY KEY);');
        file_put_contents(
            $directory . '/002_failure.sql',
            "CREATE TABLE rollback_probe (id INTEGER PRIMARY KEY);\nTHIS IS INVALID SQL;"
        );

        $migrator = new Migrator($this->pdo, $directory);
        try {
            $migrator->migrate();
            self::fail('An invalid migration succeeded.');
        } catch (PDOException) {
            self::assertFalse($this->pdo->inTransaction());
        }

        self::assertSame(
            ['first_probe', 'schema_migrations'],
            $this->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame([1], $this->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement;
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, dirname(__DIR__) . '/migrations');
    }
}
