<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Segara\Config;
use Segara\View\Renderer;
use Segara\View\Themes;

final class ThemeTest extends TestCase
{
    public function testRequiredThemeFilesExist(): void
    {
        $root = dirname(__DIR__) . '/themes';
        foreach (Themes::ALL as $theme) {
            foreach (['theme.php', 'layout.php', 'home.php', 'page.php', 'error.php'] as $file) {
                self::assertFileExists($root . '/' . $theme . '/' . $file);
            }
            self::assertFileExists($root . '/' . $theme . '/assets/theme.css');
        }
    }

    public function testSameContentAndMetadataAcrossThemes(): void
    {
        $outputs = [];
        foreach (Themes::ALL as $theme) {
            $html = $this->renderPage($theme);
            self::assertStringContainsString('Shared Title', $html);
            self::assertStringContainsString('<p>Shared body</p>', $html);
            self::assertStringContainsString('application/ld+json', $html);
            $outputs[$theme] = $html;
        }

        $first = $this->jsonLd($outputs['clarity']);
        foreach (['aubergine', 'editorial', 'scholar', 'folio', 'slate'] as $theme) {
            self::assertSame($first, $this->jsonLd($outputs[$theme]));
        }
    }

    public function testTheSlateDescriptorIsRegistered(): void
    {
        $themes = new Themes(dirname(__DIR__) . '/themes');

        self::assertContains('slate', Themes::ALL);
        self::assertSame('Slate Bootstrap', $themes->info('slate')['name']);
        self::assertSame(0, $themes->info('slate')['sidebars']);
    }

    public function testSidebarCountsMatchEachDescriptor(): void
    {
        $left = '<div class="marker-left">LEFT</div>';
        $right = '<div class="marker-right">RIGHT</div>';

        $aubergine = $this->renderPage('aubergine', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(1, substr_count($aubergine, '<aside class="sidebar'));
        self::assertStringContainsString('LEFT', $aubergine);
        self::assertStringNotContainsString('RIGHT', $aubergine);

        $clarity = $this->renderPage('clarity', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(0, substr_count($clarity, '<aside class="sidebar'));
        self::assertStringNotContainsString('LEFT', $clarity);
        self::assertStringNotContainsString('RIGHT', $clarity);

        $editorial = $this->renderPage('editorial', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(2, substr_count($editorial, '<aside class="sidebar'));
        self::assertStringContainsString('LEFT', $editorial);
        self::assertStringContainsString('RIGHT', $editorial);

        $scholar = $this->renderPage('scholar', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(1, substr_count($scholar, '<aside class="sidebar'));
        self::assertStringContainsString('LEFT', $scholar);
        self::assertStringNotContainsString('RIGHT', $scholar);

        $slate = $this->renderPage('slate', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(0, substr_count($slate, '<aside class="sidebar'));
        self::assertStringNotContainsString('LEFT', $slate);
        self::assertStringNotContainsString('RIGHT', $slate);

        $folio = $this->renderPage('folio', ['left_sidebar' => $left, 'right_sidebar' => $right]);
        self::assertSame(1, substr_count($folio, '<aside class="sidebar'));
        self::assertStringContainsString('LEFT', $folio);
        self::assertStringNotContainsString('RIGHT', $folio);
    }

    /**
     * A theme may suppress the brand. When it has also no menu there is nothing
     * left in the bar, and an empty 40px strip with a hairline is worse than no
     * bar at all, so the partial renders nothing.
     */
    public function testAThemeWithNoBrandAndNoMenuRendersNoBar(): void
    {
        $slate = $this->renderPage('slate', ['navigation' => []]);
        self::assertStringNotContainsString('<header class="site-header"', $slate);

        $clarity = $this->renderPage('clarity', ['navigation' => []]);
        self::assertStringContainsString('<header class="site-header"', $clarity, 'a branded theme still shows its bar');
        self::assertStringContainsString('class="site-title"', $clarity);
    }

    public function testNoExternalAssets(): void
    {
        foreach (Themes::ALL as $theme) {
            $html = $this->renderPage($theme);
            self::assertStringContainsString('/theme.css.php?theme=' . $theme, $html);

            preg_match_all('~<link rel="stylesheet" href="([^"]+)"~', $html, $matches);
            $links = $matches[1];
            $own = array_values(array_filter($links, static fn (string $href): bool => str_contains($href, 'theme.css.php')));
            self::assertCount(1, $own, $theme . ' links its own stylesheet exactly once');
            self::assertStringNotContainsString('://', $own[0]);

            // A remote stylesheet is a documented exception for slate only: the
            // pinned, SRI-guarded Bootstrap build. script-src stays closed.
            $remote = array_values(array_filter($links, static fn (string $href): bool => str_contains($href, '://')));
            self::assertLessThanOrEqual(1, count($remote), $theme . ' links at most one remote stylesheet');
            foreach ($remote as $href) {
                self::assertSame('slate', $theme, $theme . ' must not link a remote stylesheet');
                self::assertSame(
                    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
                    $href
                );
            }

            self::assertStringNotContainsString('<script src="', $html);

            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            self::assertStringNotContainsString('url(http', strtolower($css));
            self::assertStringNotContainsString('@import', strtolower($css));
        }
    }

    public function testThemeDescriptorsAreValid(): void
    {
        $themes = new Themes(dirname(__DIR__) . '/themes');
        self::assertSame(1, $themes->info('aubergine')['sidebars']);
        self::assertSame(0, $themes->info('clarity')['sidebars']);
        self::assertSame(2, $themes->info('editorial')['sidebars']);
        self::assertSame(1, $themes->info('scholar')['sidebars']);
        self::assertSame('Warm Scholarly Modernism', $themes->info('scholar')['name']);
        self::assertSame(1, $themes->info('folio')['sidebars']);
        self::assertSame('Warm Editorial Folio', $themes->info('folio')['name']);
        self::assertSame('Clarity Canvas', $themes->info('clarity')['name']);
    }

    public function testThemeAllowlistRejectsUnknownThemesAndNonPublicTemplates(): void
    {
        $renderer = new Renderer($this->config());
        $themes = new Themes(dirname(__DIR__) . '/themes');

        $rejected = 0;
        foreach ([
            static fn () => $renderer->render('attacker', 'page'),
            static fn () => $renderer->render('clarity', 'layout'),
            static fn () => $renderer->render('clarity', '../page'),
            static fn () => $themes->info('attacker'),
        ] as $invoke) {
            try {
                $invoke();
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected);
    }

    /** @param array<string, mixed> $extra */
    private function renderPage(string $theme, array $extra = []): string
    {
        return (new Renderer($this->config()))->render($theme, 'page', array_merge([
            'title' => 'Shared Title',
            'body' => '<p>Shared body</p>',
            'challenge' => false,
            'csrfToken' => '',
            'path' => '/about',
            'site_name' => 'Example',
        ], $extra));
    }

    private function jsonLd(string $html): string
    {
        $matched = preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
        self::assertSame(1, $matched);

        return $matches[1];
    }

    private function config(): Config
    {
        return Config::fromArray([
            'base_url' => 'https://example.test',
            'base_path' => '/segara',
            'environment' => 'test',
        ]);
    }
}
