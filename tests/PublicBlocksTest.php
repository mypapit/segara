<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOStatement;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Blocks\PublicBlocks;
use Segara\Config;
use Segara\Menus\MenuRepository;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

/**
 * The block provider must read every region in one query and expose the block
 * rows in stored order, regardless of how many regions the theme renders.
 */
final class PublicBlocksTest extends TestCase
{
    public function testEveryRegionIsServedFromASingleRead(): void
    {
        $pdo = $this->countingPdo();
        $blocks = new BlockRepository($pdo);
        $blocks->create(['location' => 'left', 'type' => 'quick-facts', 'position' => 0, 'settings' => ['facts' => [['text' => 'A', 'bullet' => '']]]]);
        $blocks->create(['location' => 'right', 'type' => 'quick-facts', 'position' => 0, 'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]]]);
        $blocks->create(['location' => 'footer', 'type' => 'address', 'position' => 0, 'settings' => ['text' => 'C']]);

        $service = new PublicBlocks($blocks, new BlockRenderer($this->config(), new MenuRepository($pdo)));

        $before = $pdo->blockQueries();
        $data = $service->layoutData();
        $service->hasFooterCopyright();

        self::assertSame($before + 1, $pdo->blockQueries(), 'the three regions must share one block read');
        self::assertStringContainsString('>A<', $data['left_sidebar']);
        self::assertStringContainsString('>B<', $data['right_sidebar']);
        self::assertStringContainsString('C', $data['footer_html']);
    }

    public function testGroupedRowsKeepStoredOrderWithinEachLocation(): void
    {
        $pdo = $this->countingPdo();
        $blocks = new BlockRepository($pdo);
        $blocks->create(['location' => 'left', 'type' => 'quick-facts', 'position' => 0, 'settings' => ['facts' => [['text' => 'First', 'bullet' => '']]]]);
        $blocks->create(['location' => 'left', 'type' => 'quick-facts', 'position' => 1, 'settings' => ['facts' => [['text' => 'Second', 'bullet' => '']]]]);

        $grouped = $blocks->listGrouped();

        self::assertCount(2, $grouped['left']);
        self::assertSame(0, $grouped['left'][0]['position']);
        self::assertSame(1, $grouped['left'][1]['position']);
        self::assertSame([], $grouped['right']);
        self::assertSame([], $grouped['footer']);
    }

    public function testFooterCopyrightIsDetected(): void
    {
        $pdo = $this->countingPdo();
        $blocks = new BlockRepository($pdo);
        $blocks->create(['location' => 'footer', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => '© 2026 Example']]);

        $service = new PublicBlocks($blocks, new BlockRenderer($this->config(), new MenuRepository($pdo)));

        self::assertTrue($service->hasFooterCopyright());
    }

    public function testFooterCopyrightIsAbsentWhenOnlyOtherTypes(): void
    {
        $pdo = $this->countingPdo();
        $blocks = new BlockRepository($pdo);
        $blocks->create(['location' => 'footer', 'type' => 'address', 'position' => 0, 'settings' => ['text' => 'Somewhere']]);

        $service = new PublicBlocks($blocks, new BlockRenderer($this->config(), new MenuRepository($pdo)));

        self::assertFalse($service->hasFooterCopyright());
    }

    private function countingPdo(): CountingPdo
    {
        $pdo = new CountingPdo('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();

        return $pdo;
    }

    private function config(): Config
    {
        return Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
    }
}

/**
 * Counts block reads so a per-region query cannot hide behind a memoised facade.
 */
final class CountingPdo extends PDO
{
    private int $blockReads = 0;

    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (stripos($query, 'FROM blocks') !== false) {
            $this->blockReads++;
        }

        return parent::prepare($query, $options);
    }

    public function blockQueries(): int
    {
        return $this->blockReads;
    }
}
