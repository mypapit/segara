<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Blocks\BlockRepository;
use Segara\Blocks\BlockSeeder;
use Segara\Menus\MenuRepository;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class SeedBlocksTest extends TestCase
{
    private PDO $pdo;
    private BlockRepository $blocks;
    private MenuRepository $menus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->blocks = new BlockRepository($this->pdo);
        $this->menus = new MenuRepository($this->pdo);
    }

    public function testItCreatesSidebarAndFooterDefaults(): void
    {
        $this->menus->createMenu('Primary');

        $ids = (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026))->seed();

        self::assertCount(4, $ids);
        $left = array_column($this->blocks->listByLocation('left'), 'type');
        self::assertSame(['menu', 'quick-facts', 'rss'], $left);
        $footer = array_column($this->blocks->listByLocation('footer'), 'type');
        self::assertSame(['copyright'], $footer);
    }

    public function testTheCopyrightCarriesTheSiteNameAndYear(): void
    {
        (new BlockSeeder($this->blocks, $this->menus, 'Alor Setar Notes', 2026))->seed();

        $footer = $this->blocks->listByLocation('footer');
        self::assertCount(1, $footer);
        $settings = json_decode($footer[0]['settings_json'], true);
        self::assertIsArray($settings);
        self::assertSame('© 2026 Alor Setar Notes. All rights reserved.', $settings['text'] ?? null);
    }

    public function testItIsIdempotent(): void
    {
        (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026))->seed();
        $before = count($this->blocks->listAll());

        $second = (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026))->seed();

        self::assertSame([], $second, 'a second run must create nothing');
        self::assertSame($before, count($this->blocks->listAll()));
    }

    public function testItSkipsTheMenuBlockWhenNoMenuExists(): void
    {
        $ids = (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026))->seed();

        self::assertCount(3, $ids, 'a menu block with no menu would render nothing');
        self::assertNotContains('menu', array_column($this->blocks->listAll(), 'type'));
    }

    public function testTheDefaultCopyrightNamesTheSiteSegara(): void
    {
        // bin/setup never writes site_name, so the fallback is what a fresh
        // install actually prints.
        (new BlockSeeder($this->blocks, $this->menus))->seed();

        $footer = $this->blocks->listByLocation('footer');
        $settings = json_decode($footer[0]['settings_json'] ?? '[]', true);
        self::assertIsArray($settings);
        $text = $settings['text'] ?? '';
        self::assertIsString($text);
        self::assertStringContainsString('Segara', $text);
        self::assertStringNotContainsString('this site', $text);
    }

    public function testTheMenuBlockUsesTheHeaderMenuNotTheOldestOne(): void
    {
        $first = $this->menus->createMenu('Alpha');
        $primary = $this->menus->createMenu('Zulu primary');
        (new SettingsRepository($this->pdo))->setMany(['primary_menu_id' => $primary['id']]);

        (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026, new SettingsRepository($this->pdo)))->seed();

        $menuBlock = null;
        foreach ($this->blocks->listAll() as $block) {
            if ($block['type'] === 'menu') {
                $menuBlock = $block;
            }
        }
        self::assertNotNull($menuBlock);
        $settings = json_decode($menuBlock['settings_json'], true);
        self::assertIsArray($settings);
        self::assertArrayHasKey('menu_id', $settings);
        self::assertSame($primary['id'], $settings['menu_id']);
        self::assertNotSame($first['id'], $settings['menu_id']);
    }

    public function testAFailedSeedLeavesNoPartialRows(): void
    {
        $this->menus->createMenu('Primary');

        try {
            // The copyright text limit is 5000 characters, so the last block fails
            // after the first three have already been written.
            (new BlockSeeder($this->blocks, $this->menus, str_repeat('A', 6000), 2026))->seed();
            self::fail('the seeder should have thrown');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Copyright', $exception->getMessage());
        }

        self::assertSame([], $this->blocks->listAll(), 'a failed seed must leave the store empty');
    }

    public function testQuickFactsAreObviousPlaceholdersNotLoremIpsum(): void
    {
        (new BlockSeeder($this->blocks, $this->menus, 'Segara', 2026))->seed();

        foreach ($this->blocks->listByLocation('left') as $block) {
            if ($block['type'] !== 'quick-facts') {
                continue;
            }
            $settings = json_decode($block['settings_json'], true);
            self::assertIsArray($settings);
            $facts = $settings['facts'] ?? [];
            self::assertIsArray($facts);
            self::assertCount(3, $facts);
            $first = $facts[0] ?? [];
            self::assertIsArray($first);
            self::assertSame('Founded in 2026', $first['text'] ?? null);
            self::assertSame('★', $first['bullet'] ?? null);

            return;
        }

        self::fail('no quick-facts block was seeded');
    }
}
