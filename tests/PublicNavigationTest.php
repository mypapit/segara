<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Blocks\PublicBlocks;
use Segara\Config;
use Segara\Media\MediaRepository;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\PublicSite\HomeController;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

/**
 * The public navigation bar: the administrator's menu must reach the header,
 * nesting must be preserved, and malformed parent chains must not hang the
 * renderer.
 */
final class PublicNavigationTest extends TestCase
{
    private const THEMES = ['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'];

    private ?PDO $pdo = null;

    public function testPrimaryMenuItemsAppearInTheHeader(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $this->item($menu, 'About', '/about');
        $this->item($menu, 'Writing', '/writing');

        $html = $this->home()->index()->body();

        self::assertStringContainsString('<nav class="site-nav" aria-label="Primary">', $html);
        self::assertStringContainsString('>About</a>', $html);
        self::assertStringContainsString('>Writing</a>', $html);
    }

    public function testAChildItemRendersInsideItsParentListItem(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $parent = $this->item($menu, 'Research', '/research');
        $this->item($menu, 'Publications', '/publications', $parent);

        $html = $this->home()->index()->body();

        $start = strpos($html, '>Research</a>');
        self::assertNotFalse($start);
        $end = strpos($html, '</li>', (int) $start);
        self::assertNotFalse($end);
        $parentItem = substr($html, (int) $start, (int) $end - (int) $start);

        self::assertStringContainsString('>Publications</a>', $parentItem, 'the child must nest inside the parent item');
    }

    public function testNoMenuRendersNoNavigationElement(): void
    {
        $this->seedTheme('clarity');

        self::assertStringNotContainsString('site-nav', $this->home()->index()->body());
    }

    public function testStoredCycleDoesNotHangAndKeepsTheRestOfTheMenu(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $this->item($menu, 'Home', '/home');

        // A cycle has no null-parent root, so the renderer must simply skip it
        // rather than recurse until the stack overflows.
        $first = bin2hex(random_bytes(16));
        $second = bin2hex(random_bytes(16));
        $this->rawItem($first, $menu, null, 'Loop A');
        $this->rawItem($second, $menu, null, 'Loop B');
        $statement = $this->pdo()->prepare('UPDATE menu_items SET parent_id = :parent WHERE id = :id');
        $statement->execute(['parent' => $second, 'id' => $first]);
        $statement->execute(['parent' => $first, 'id' => $second]);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('>Home</a>', $html);
    }

    public function testNestedMenuKeepsTheArticleFirstOnNarrowScreens(): void
    {
        foreach (['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'] as $theme) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            self::assertMatchesRegularExpression(
                '/\.site-nav ul \{[^}]*flex-wrap:\s*wrap/s',
                $css,
                $theme . ' navigation must wrap instead of overflowing'
            );
        }
    }

    public function testAMenuWithNothingReachableRendersNoEmptyLandmark(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $first = bin2hex(random_bytes(16));
        $second = bin2hex(random_bytes(16));
        $this->rawItem($first, $menu, null, 'Loop A');
        $this->rawItem($second, $menu, null, 'Loop B');
        $statement = $this->pdo()->prepare('UPDATE menu_items SET parent_id = :parent WHERE id = :id');
        $statement->execute(['parent' => $second, 'id' => $first]);
        $statement->execute(['parent' => $first, 'id' => $second]);

        $html = $this->home()->index()->body();

        self::assertStringNotContainsString('site-nav', $html, 'an empty named landmark is worse than none');
    }

    public function testEveryThemeLeavesTheBarWithTheBrandOnTheLeftAndTheMenuOnTheRight(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->themeCss($theme);

            self::assertMatchesRegularExpression(
                '/\.header-inner\s*\{[^}]*justify-content:\s*space-between/s',
                $css,
                $theme . ' separates the brand from the menu inside the content column'
            );
            self::assertMatchesRegularExpression(
                '/\.site-nav\s*\{[^}]*margin-left:\s*auto/s',
                $css,
                $theme . ' pins the menu to the right edge'
            );
            self::assertMatchesRegularExpression('/--header-bg:/', $css, $theme . ' defines a header surface');
            self::assertMatchesRegularExpression(
                '/\.site-header\s*\{[^}]*background:\s*var\(--header-bg\)/s',
                $css,
                $theme . ' uses its header surface'
            );
            self::assertMatchesRegularExpression(
                '/\.site-nav a:hover[^{]*\{[^}]*(border-bottom-color|text-decoration)/s',
                $css,
                $theme . ' gives the menu links a hover affordance'
            );
        }
    }

    public function testEveryThemeStylesheetHasBalancedBraces(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->themeCss($theme);
            self::assertSame(
                substr_count($css, '{'),
                substr_count($css, '}'),
                $theme . ' stylesheet has a stray brace'
            );
        }
    }

    public function testTheMenuMarkupFollowsTheBrand(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $this->item($menu, 'Notes', '/notes');

        $body = $this->home()->index()->body();
        $brandAt = strpos($body, 'class="site-title"');
        $navAt = strpos($body, 'class="site-nav"');

        self::assertNotFalse($brandAt);
        self::assertNotFalse($navAt);
        self::assertLessThan($navAt, $brandAt, 'the brand renders before the menu, so the menu is the right-hand item');
    }

    /**
     * The reference site pins the brand and the menu to a centred content column.
     * Both the article and the header must read their width from the same tokens,
     * or the bar drifts by half the leftover viewport width on a wide screen.
     */
    public function testTheHeaderSharesTheContentColumnOfEveryTheme(): void
    {
        $expected = [
            'clarity' => ['1016px', '1.5rem'],
            'aubergine' => ['1140px', '1.5rem'],
            'editorial' => ['1280px', '1.5rem'],
            'scholar' => ['1400px', '2.5rem'],
        ];

        foreach ($expected as $theme => [$max, $pad]) {
            $css = $this->themeCss($theme);

            self::assertStringContainsString('--content-max: ' . $max . ';', $css, $theme . ' declares the content width');
            self::assertStringContainsString('--content-pad: ' . $pad . ';', $css, $theme . ' declares the content padding');

            self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css, $theme . ' layout takes its width from the token');
            self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*padding:[^;]*var\(--content-pad\)/s', $css, $theme . ' layout takes its inset from the token');

            self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css, $theme . ' header shares the column width');
            self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*margin:\s*0 auto/s', $css, $theme . ' header column is centred');
            self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*padding:[^;]*var\(--content-pad\)/s', $css, $theme . ' header shares the content inset');
        }
    }

    public function testTheHeaderBarIsSurfaceOnlyAndTheInnerRowCarriesTheLayout(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->themeCss($theme);

            // The bar keeps the sticky surface and the hairline...
            self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*position:\s*sticky/s', $css, $theme . ' bar stays sticky');
            self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*background:\s*var\(--header-bg\)/s', $css, $theme . ' bar keeps its surface');

            // ...but the flex row and the horizontal padding moved to the inner element.
            self::assertDoesNotMatchRegularExpression('/\.site-header\s*\{[^}]*display:\s*flex/s', $css, $theme . ' bar no longer lays out the row');
            self::assertDoesNotMatchRegularExpression('/\.site-header\s*\{[^}]*padding:/s', $css, $theme . ' bar no longer insets the content');
        }
    }

    public function testTheHeaderRendersOneInnerColumnAroundTheBrandAndTheMenu(): void
    {
        $this->seedTheme('clarity');
        $menu = $this->menu('Primary');
        $this->item($menu, 'Notes', '/notes');

        $body = $this->home()->index()->body();

        self::assertStringContainsString('<div class="header-inner">', $body);
        self::assertSame(1, substr_count($body, 'class="header-inner"'), 'exactly one inner column');

        $innerAt = strpos($body, 'class="header-inner"');
        $brandAt = strpos($body, 'class="site-title"');
        $navAt = strpos($body, 'class="site-nav"');
        $closeAt = strpos($body, '</header>');
        self::assertNotFalse($innerAt);
        self::assertNotFalse($brandAt);
        self::assertNotFalse($navAt);
        self::assertNotFalse($closeAt);
        self::assertLessThan($brandAt, $innerAt, 'the column opens before the brand');
        self::assertLessThan($navAt, $brandAt, 'the brand still precedes the menu');
        self::assertLessThan($closeAt, $navAt, 'the column closes inside the header');
    }

    private function home(): HomeController
    {
        $menuRepository = new MenuRepository($this->pdo());
        $menus = $menuRepository->listMenus();
        $primaryId = (new SettingsRepository($this->pdo()))->get('primary_menu_id') ?? ($menus[0]['id'] ?? null);
        $navigation = is_string($primaryId) ? $menuRepository->navigation($primaryId) : [];

        return new HomeController(
            $this->config(),
            new PageRepository($this->pdo()),
            new Renderer($this->config(), null, new SettingsRepository($this->pdo())),
            new PublicBlocks(
                new BlockRepository($this->pdo()),
                new BlockRenderer($this->config(), $menuRepository, new MediaRepository($this->pdo())),
            ),
            $navigation,
        );
    }

    private function themeCss(string $theme): string
    {
        $css = file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
        self::assertIsString($css, $theme . ' stylesheet is readable');

        return $css;
    }

    private function menu(string $name): string
    {
        return (new MenuRepository($this->pdo()))->createMenu($name)['id'];
    }

    private function item(string $menuId, string $label, string $url, ?string $parentId = null): string
    {
        $repository = new MenuRepository($this->pdo());
        $position = count($repository->listItems($menuId));

        return $repository->addItem([
            'menu_id' => $menuId,
            'label' => $label,
            'target_type' => 'custom',
            'custom_url' => $url,
            'parent_id' => $parentId,
            'position' => $position,
        ])['id'];
    }

    private function rawItem(string $id, string $menuId, ?string $parentId, string $label): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO menu_items (id, menu_id, parent_id, target_type, page_id, custom_url, label, position) '
            . 'VALUES (:id, :menu_id, :parent_id, :target_type, NULL, :url, :label, 0)'
        );
        $statement->execute([
            'id' => $id,
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'target_type' => 'custom',
            'url' => '/loop',
            'label' => $label,
        ]);
    }

    private function seedTheme(string $theme): void
    {
        (new SettingsRepository($this->pdo()))->setMany(['theme' => $theme]);
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
