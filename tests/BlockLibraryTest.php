<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Config;
use Segara\Media\MediaRepository;
use Segara\Menus\MenuRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

/**
 * The block catalogue is only "present out of the box" when each type has both a
 * renderer root and a visual layer. These tests pin the contract between the two:
 * the class names the renderer emits and the selectors the themes style.
 */
final class BlockLibraryTest extends TestCase
{
    private const THEMES = ['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'];

    /** Every type must have a root class, and every root class must be styled. */
    private const TYPES = [
        'menu' => 'block--menu',
        'images' => 'block--images',
        'rss' => 'block--rss',
        'social' => 'block--social',
        'external-links' => 'block--links',
        'quick-facts' => 'block--facts',
        'custom-inline' => 'block--custom',
        'copyright' => 'block--copyright',
        'address' => 'block--address',
        'blurb' => 'block--blurb',
    ];

    private PDO $pdo;
    private BlockRenderer $renderer;
    private MenuRepository $menus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->menus = new MenuRepository($this->pdo);
        $this->renderer = new BlockRenderer(
            Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']),
            $this->menus,
            new MediaRepository($this->pdo),
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}
     */
    private function block(string $type, array $settings): array
    {
        return [
            'id' => str_repeat('a', 32),
            'location' => 'left',
            'position' => 0,
            'type' => $type,
            'settings_json' => json_encode($settings, JSON_THROW_ON_ERROR),
            'revision' => 1,
        ];
    }

    public function testEveryTypeHasItsRootClassInTheMarkup(): void
    {
        $samples = [
            'menu' => [],
            'images' => ['image_ids' => [str_repeat('b', 32)]],
            'rss' => ['title' => 'Subscribe'],
            'social' => ['networks' => [['provider' => 'github', 'url' => 'https://example.test/gh']]],
            'external-links' => ['links' => [['label' => 'Example', 'url' => 'https://example.test']]],
            'quick-facts' => ['facts' => [['text' => 'Founded 2026', 'bullet' => '★']]],
            'custom-inline' => ['html' => 'A sentence.'],
            'copyright' => ['text' => '© 2026 Test'],
            'address' => ['text' => "12 Jalan Merdeka\n50450 Kuala Lumpur"],
            'blurb' => ['text' => 'A short biography.'],
        ];
        $menu = $this->menus->createMenu('Primary');
        $this->menus->addItem([
            'menu_id' => $menu['id'], 'label' => 'Home', 'target_type' => 'custom',
            'custom_url' => '/about', 'parent_id' => null, 'position' => 0,
        ]);
        $settings = $samples['menu'];
        $settings['menu_id'] = $menu['id'];

        foreach (self::TYPES as $type => $class) {
            $html = $this->renderer->render($this->block($type, $type === 'menu' ? $settings : $samples[$type]));
            self::assertStringContainsString($class, $html, $type . ' emits ' . $class);
        }
    }

    public function testEveryThemeStylesEveryBlockType(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->themeCss($theme);
            foreach (self::TYPES as $type => $class) {
                self::assertStringContainsString('.' . $class, $css, $theme . ' styles ' . $class);
            }
        }
    }

    public function testEveryThemeSpanstheImagesHeadingAcrossTheGrid(): void
    {
        foreach (self::THEMES as $theme) {
            // The renderer emits <h2> and <img> as siblings, so without this the
            // heading takes the first image cell.
            self::assertMatchesRegularExpression(
                '/\.block--images\s*>\s*h2\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s',
                $this->themeCss($theme),
                $theme . ' spans the images heading'
            );
        }
    }

    public function testEveryThemeWrapsLongBlockContent(): void
    {
        foreach (self::THEMES as $theme) {
            self::assertMatchesRegularExpression('/\.block\s*\{[^}]*overflow-wrap:\s*anywhere/s', $this->themeCss($theme));
        }
    }

    public function testEveryThemeSizesTheSpriteIcon(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->themeCss($theme);
            // An inline <svg> with no CSS is 300x150 or 0x0 depending on the engine.
            self::assertMatchesRegularExpression('/\.icon\s*\{[^}]*width:/s', $css, $theme . ' sizes .icon');
        }
    }

    public function testEveryThemeStylesTheCustomBullet(): void
    {
        foreach (self::THEMES as $theme) {
            self::assertStringContainsString('[data-bullet]', $this->themeCss($theme));
        }
    }

    public function testTheRssBlockRendersTheChickletLogo(): void
    {
        $html = $this->renderer->render($this->block('rss', ['title' => 'Subscribe']));

        self::assertStringContainsString('icons.svg#icon-rss', $html, 'the chicklet needs a logo, not a bare text link');
        self::assertStringContainsString('rss-chicklet', $html);
    }

    public function testTheSpriteCarriesTheRssGlyph(): void
    {
        $sprite = (string) file_get_contents(dirname(__DIR__) . '/public/assets/icons.svg');

        self::assertStringContainsString('id="icon-rss"', $sprite);
    }

    public function testSocialLinksCarryAnAccessibleName(): void
    {
        $html = $this->renderer->render($this->block('social', [
            'networks' => [
                ['provider' => 'github', 'url' => 'https://example.test/gh'],
                ['provider' => 'mastodon', 'url' => 'https://example.test/mastodon'],
            ],
        ]));

        self::assertStringContainsString('aria-label="GitHub"', $html);
        self::assertStringContainsString('aria-label="Mastodon"', $html);
        self::assertStringContainsString('rel="me"', $html);
        // One accessible name, not an aria-label plus a hidden duplicate.
        self::assertSame(1, substr_count($html, 'GitHub'), 'the provider name appears once per link');
    }

    public function testQuickFactsEmitEscapedCustomBullets(): void
    {
        $html = $this->renderer->render($this->block('quick-facts', [
            'facts' => [
                ['text' => 'Founded 2026', 'bullet' => '★'],
                ['text' => 'Based in Alor Setar', 'bullet' => '→'],
                ['text' => 'No bullet asked for', 'bullet' => ''],
            ],
        ]));

        self::assertStringContainsString('data-bullet="★"', $html);
        self::assertStringContainsString('data-bullet="→"', $html);
        self::assertStringNotContainsString('data-bullet=""', $html);
    }

    public function testTheImagesBlockKeepsTheStoredAltText(): void
    {
        $media = new MediaRepository($this->pdo);
        $item = $media->create([
            'original_name' => 'chart.png', 'stored_path' => str_repeat('cd', 16) . '.png', 'mime' => 'image/png',
            'size' => 512, 'alt_text' => 'Enrolment by year', 'decorative' => 0,
        ]);

        $html = $this->renderer->render($this->block('images', ['image_ids' => [$item['id']]]));

        self::assertStringContainsString('alt="Enrolment by year"', $html);
    }

    public function testABlurbWithoutAnImageHasNoEmptyFigure(): void
    {
        $html = $this->renderer->render($this->block('blurb', ['title' => 'About', 'text' => 'A short biography.']));

        self::assertStringNotContainsString('<figure', $html);
        self::assertStringContainsString('A short biography.', $html);
    }

    private function themeCss(string $theme): string
    {
        $path = dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css';
        $css = file_get_contents($path);
        self::assertIsString($css, $theme . ' stylesheet is readable');

        return $css;
    }
}
