<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Blocks\PublicBlocks;
use Segara\Config;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\PublicSite\HomeController;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

/**
 * Behaviour of the public sidebar regions: blocks authored in the admin must
 * reach the rendered page, and a region with nothing in it must not emit an
 * empty landmark.
 */
final class PublicSidebarTest extends TestCase
{
    private ?PDO $pdo = null;

    public function testLeftBlockRendersInsideTheRail(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('left', 'quick-facts', [
            'title' => 'About',
            'facts' => [['text' => 'Founded 2026', 'bullet' => '']],
        ]);

        $html = $this->home()->index()->body();

        $start = strpos($html, '<aside class="sidebar sidebar--rail"');
        self::assertNotFalse($start, 'the rail must be rendered');
        $end = strpos($html, '</aside>', (int) $start);
        self::assertNotFalse($end, 'the rail must be closed');
        $aside = substr($html, (int) $start, (int) $end - (int) $start);

        self::assertStringContainsString('Founded 2026', $aside, 'the block must sit inside the aside, not in main');
    }

    public function testASidebarWithNoBlocksRendersNoAside(): void
    {
        $this->seedTheme('aubergine');

        self::assertStringNotContainsString('<aside', $this->home()->index()->body());
    }

    public function testThemeWithoutSidebarsRendersNoAsideEvenWithLeftBlocks(): void
    {
        $this->seedTheme('clarity');
        $this->seedBlock('left', 'quick-facts', [
            'title' => 'About',
            'facts' => [['text' => 'Founded 2026', 'bullet' => '']],
        ]);

        $html = $this->home()->index()->body();

        self::assertStringNotContainsString('<aside', $html);
        self::assertStringNotContainsString('Founded 2026', $html);
    }

    public function testRightSidebarDoesNotRenderInAOneSidebarTheme(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('right', 'quick-facts', [
            'title' => 'Elsewhere',
            'facts' => [['text' => 'RightOnlyMarker', 'bullet' => '']],
        ]);

        self::assertStringNotContainsString('RightOnlyMarker', $this->home()->index()->body());
    }

    public function testTwoSidebarThemeRendersBothColumns(): void
    {
        $this->seedTheme('editorial');
        $this->seedBlock('left', 'quick-facts', [
            'title' => 'Left',
            'facts' => [['text' => 'LeftMarker', 'bullet' => '']],
        ]);
        $this->seedBlock('right', 'quick-facts', [
            'title' => 'Right',
            'facts' => [['text' => 'RightMarker', 'bullet' => '']],
        ]);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('sidebar--left', $html);
        self::assertStringContainsString('sidebar--right', $html);
        self::assertStringContainsString('LeftMarker', $html);
        self::assertStringContainsString('RightMarker', $html);
    }

    public function testThemeGridOnlyReservesTracksForRenderedSidebars(): void
    {
        $expected = [
            'clarity' => ['.layout:has(.sidebar--left) { grid-template-columns: 260px 1fr; }'],
            // One rail, on the right: the article keeps the first track.
            'aubergine' => ['.layout:has(.sidebar--rail) { grid-template-columns: minmax(0, 1fr) 300px; }'],
            'scholar' => ['.layout:has(.sidebar--rail) { grid-template-columns: minmax(0, 860px) 300px;'],
            'folio' => ['.layout:has(.sidebar--rail) { grid-template-columns: minmax(0, 1fr) 320px;'],
            'editorial' => [
                '.layout:has(.sidebar--left) { grid-template-columns: 220px minmax(0, 720px);',
                '.layout:has(.sidebar--right) { grid-template-columns: minmax(0, 720px) 260px;',
                '.layout:has(.sidebar--left):has(.sidebar--right) { grid-template-columns: 220px minmax(0, 720px) 260px; }',
            ],
        ];

        foreach ($expected as $theme => $declarations) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            foreach ($declarations as $declaration) {
                self::assertStringContainsString($declaration, $css, $theme . ' grid rule changed');
            }
            // A track may only be reserved when the aside actually exists.
            self::assertDoesNotMatchRegularExpression(
                '/\.layout \{ grid-template-columns: [^}]*px/',
                $css,
                $theme . ' must not reserve a sidebar track unconditionally'
            );
        }

        $descriptor = require dirname(__DIR__) . '/themes/clarity/theme.php';
        self::assertIsArray($descriptor);
        self::assertSame(0, $descriptor['sidebars'], 'clarity is the deliberate no-sidebar theme');
    }

    public function testAdminCopyrightBlockSuppressesTheGeneratedLine(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('footer', 'copyright', ['text' => '© 2026 Alpha Authored']);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('© 2026 Alpha Authored', $html);
        self::assertStringNotContainsString('© 2026 Segara', $html, 'the generated line must yield to the authored block');
        self::assertSame(1, substr_count($html, '©'));
    }

    public function testGeneratedCopyrightStillAppearsWithoutACopyrightBlock(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('footer', 'address', ['text' => '1 Admin Street']);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('© 2026 Segara', $html);
        self::assertStringContainsString('1 Admin Street', $html);
        self::assertSame(1, substr_count($html, '©'));
    }

    public function testEverySidebarStacksAfterTheMainContentOnNarrowScreens(): void
    {
        $rails = [
            'clarity' => '.sidebar--left',
            'aubergine' => '.sidebar--rail',
            'editorial' => '.sidebar--left',
            'scholar' => '.sidebar--rail',
            'folio' => '.sidebar--rail',
        ];

        foreach ($rails as $theme => $selector) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            self::assertStringContainsString($selector . ' { order:', $css, $theme . ' must let the article come first when stacked');
        }
    }

    /**
     * A theme that declares a single sidebar puts it on the right. Both the DOM
     * order and the grid have to agree, or the rail reads before the article.
     */
    public function testSingleSidebarThemesPlaceTheirRailOnTheRight(): void
    {
        foreach (['aubergine', 'scholar'] as $theme) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            self::assertMatchesRegularExpression(
                '/\.layout:has\(\.sidebar--rail\)\s*\{\s*grid-template-columns:\s*minmax\(0,/',
                $css,
                $theme . ' gives the article the first track and the rail the second'
            );
            self::assertStringNotContainsString('.layout:has(.sidebar--left)', $css, $theme . ' has no left rail any more');

            $layout = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/layout.php');
            $mainAt = strpos($layout, '<main id="main"');
            $asideAt = strpos($layout, 'class="sidebar sidebar--rail"');
            self::assertNotFalse($mainAt);
            self::assertNotFalse($asideAt);
            self::assertLessThan($asideAt, $mainAt, $theme . ' renders the article before the rail');
            self::assertStringNotContainsString('sidebar--left', $layout, $theme . ' has no left rail in the markup');
        }
    }

    private function home(): HomeController
    {
        return new HomeController(
            $this->config(),
            new PageRepository($this->pdo()),
            new Renderer($this->config(), null, new SettingsRepository($this->pdo())),
            new PublicBlocks(
                new BlockRepository($this->pdo()),
                new BlockRenderer($this->config(), new MenuRepository($this->pdo())),
            ),
        );
    }

    private function seedTheme(string $theme): void
    {
        (new SettingsRepository($this->pdo()))->setMany(['theme' => $theme]);
    }

    /** @param array<string, mixed> $settings */
    private function seedBlock(string $location, string $type, array $settings): void
    {
        (new BlockRepository($this->pdo()))->create([
            'location' => $location,
            'type' => $type,
            'position' => 0,
            'settings' => $settings,
        ]);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $pdo = Database::connect($this->sqlitePath);
            (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }

    private function config(): Config
    {
        return Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
    }
}
