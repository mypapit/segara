<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Segara\View\Themes;

/**
 * Folio is Warm Editorial (DESIGN-3) with one rail on the right and the
 * administration dashboard's old-style serif carried through the whole reading
 * surface. These assertions pin the three things that make it a distinct theme
 * rather than a copy: the serif actually matches the dashboard, the palette
 * stays editorial's, and the single rail sits on the right.
 */
final class FolioThemeTest extends TestCase
{
    public function testTheDescriptorDeclaresOneRightRail(): void
    {
        $themes = new Themes(dirname(__DIR__) . '/themes');
        $info = $themes->info('folio');

        self::assertSame('Warm Editorial Folio', $info['name']);
        self::assertSame(1, $info['sidebars']);
        self::assertSame('#faf9f6', $info['swatch']['surface']);
        self::assertSame('#111827', $info['swatch']['primary']);
        self::assertSame('#d97706', $info['swatch']['accent']);
    }

    public function testTheSerifIsTheDashboardSerifVerbatim(): void
    {
        $css = $this->css();

        $admin = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin.css');
        $found = preg_match('/--font-serif:\s*([^;]+);/', $admin, $match);
        self::assertSame(1, $found, 'the dashboard declares --font-serif');
        $dashboardSerif = trim($match[1]);
        self::assertStringContainsString('Iowan Old Style', $dashboardSerif);

        self::assertSame(
            $dashboardSerif . ';',
            $this->rawToken($css, '--font-head') . ';',
            'the heading font is the dashboard serif, unchanged'
        );
        self::assertSame(
            $dashboardSerif . ';',
            $this->rawToken($css, '--font-body') . ';',
            'body copy is set in the same serif, so the theme reads as one typeface'
        );
    }

    public function testTheBodyAndHeadingsBothUseTheSerif(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-family:\s*var\(--font-body\)/s', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-size:\s*17px/s', $css);
        self::assertMatchesRegularExpression('/h1, h2, h3\s*\{[^}]*font-family:\s*var\(--font-head\)/s', $css);
        self::assertMatchesRegularExpression('/h1, h2, h3\s*\{[^}]*font-weight:\s*600/s', $css);
        // A pronounced serif needs a tight, large display size, as the dashboard's h1 has.
        self::assertMatchesRegularExpression('/\.hero h1\s*\{[^}]*font-size:\s*clamp\(/s', $css);
        self::assertMatchesRegularExpression('/\.page-content h1\s*\{[^}]*font-size:\s*clamp\(/s', $css);
    }

    public function testThePaletteStaysEditors(): void
    {
        $folio = $this->css();
        $editorial = (string) file_get_contents(dirname(__DIR__) . '/themes/editorial/assets/theme.css');

        foreach (['--surface', '--canvas', '--on-surface', '--primary', '--secondary', '--accent',
                  '--footer-bg', '--footer-ink', '--footer-accent', '--header-bg', '--header-line'] as $token) {
            self::assertSame(
                $this->rawToken($editorial, $token),
                $this->rawToken($folio, $token),
                $token . ' is carried over from Warm Editorial'
            );
        }
    }

    public function testTheRailSitsOnTheRightAndTheArticleComesFirst(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.layout:has\(\.sidebar--rail\)\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+320px/s',
            $css,
            'the rail takes a fixed second track'
        );
        self::assertStringNotContainsString('.sidebar--left', $css, 'folio has no left rail');
        self::assertMatchesRegularExpression('/\.sidebar--rail\s*\{\s*order:/s', $css);

        $layout = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/layout.php');
        $mainAt = strpos($layout, '<main id="main"');
        $asideAt = strpos($layout, 'class="sidebar sidebar--rail"');
        self::assertNotFalse($mainAt);
        self::assertNotFalse($asideAt);
        self::assertLessThan($asideAt, $mainAt, 'the article precedes the rail in the source');
    }

    public function testTheHeaderSharesTheContentColumn(): void
    {
        $css = $this->css();

        self::assertStringContainsString('--content-max: 1200px;', $css);
        self::assertStringContainsString('--content-pad: 1.5rem;', $css);
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
    }

    public function testTheSharedContractsAreCarriedOver(): void
    {
        $css = $this->css();

        // The ten-type block library, the contact form card and the footer band
        // are part of every theme; a new theme that skips one is broken.
        self::assertStringContainsString('.block--facts li[data-bullet]::before', $css);
        self::assertStringContainsString('.block--social a', $css);
        self::assertStringContainsString('.rss-chicklet', $css);
        self::assertStringContainsString('.contact-form button', $css);
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*background:\s*var\(--footer-bg\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*border-top:\s*6px solid var\(--footer-accent\)/s', $css);
    }

    public function testTheThemeStaysCspClean(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('@import', strtolower($css));
        self::assertStringNotContainsString('url(http', strtolower($css));
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'), 'braces must balance');

        foreach (['layout.php', 'page.php', 'home.php', 'error.php', '500.php', 'partials/page-card.php'] as $template) {
            $php = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/' . $template);
            self::assertStringNotContainsString('style="', $php, $template . ' must not use inline styles (CSP)');
            self::assertStringNotContainsString('://', $php, $template . ' must not link an external asset');
        }
    }

    /**
     * The reported defect. Folio was the only theme of six whose body was a
     * block with no minimum height, so on a one-entry index the band stopped
     * where the content stopped: measured 226px of page ground under it.
     */
    public function testTheBandClosesThePageEvenWhenTheContentIsShort(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/body\s*\{[^}]*display:\s*flex;\s*\n\s*flex-direction:\s*column;\s*\n\s*min-height:\s*100vh;\s*\n\s*min-height:\s*100svh/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*flex:\s*1 0 auto/s', $css);
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*align-content:\s*start/s', $css);
    }

    /** The index had no heading at all: the document could not name itself. */
    public function testTheIndexOpensWithItsOwnHead(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/home.php');
        $css = $this->css();

        self::assertStringContainsString('<h1 class="folio-head__title">', $home);
        self::assertStringContainsString('folio-head__facts', $home);
        self::assertStringContainsString('<dt>Entries</dt>', $home);
        self::assertStringContainsString('<dt>Latest</dt>', $home);
        // Never above the heading.
        self::assertLessThan(
            (int) strpos($home, 'folio-head__facts'),
            (int) strpos($home, '<h1 class="folio-head__title">'),
        );
        self::assertMatchesRegularExpression('/\.folio-head__title\s*\{[^}]*font-size:\s*clamp\(/s', $css);
        self::assertMatchesRegularExpression(
            '/\.folio-head__facts\s*\{[^}]*border-top:\s*1px solid var\(--border\)/s',
            $css,
        );
    }

    /** An entry was a title in a box: no date, no tags, no read affordance. */
    public function testAnEntryCarriesItsDateTagsAndReadAffordance(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/home.php');
        $card = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/partials/page-card.php');
        $css = $this->css();

        self::assertStringContainsString("require __DIR__ . '/partials/page-card.php'", $home);
        self::assertStringNotContainsString('shared/page-card.php', $home);
        self::assertMatchesRegularExpression('/<time class="page-card__date" datetime="[^"]+"/', $card);
        self::assertStringContainsString("array_slice(\$page['tags'], 0, 3)", $card);
        self::assertSame(1, substr_count($card, '<a '), 'one link per entry, so one tab stop');

        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*position:\s*relative/s', $css);
        self::assertMatchesRegularExpression(
            '/\.page-card h2 a::after\s*\{[^}]*position:\s*absolute;\s*\n\s*inset:\s*0/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.page-card__more\s*\{[^}]*margin-top:\s*auto/s', $css);
    }

    /** The article had no reading time; it does now, at the caption step. */
    public function testTheArticleStatesItsReadingTimeBelowTheHeading(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__) . '/themes/folio/page.php');
        $css = $this->css();

        $head = strpos($page, 'class="article-head"');
        self::assertIsInt($head);
        $heading = strpos($page, '</h1>', $head);
        $meta = strpos($page, 'article-meta', $head);
        self::assertIsInt($heading);
        self::assertIsInt($meta);
        self::assertGreaterThan((int) $heading, (int) $meta);
        self::assertStringContainsString('min read', $page);

        // ".page-content p" sets 1.0625rem and outranks a bare .article-meta,
        // which rendered this caption at body size until it was scoped.
        self::assertMatchesRegularExpression(
            '/\.page-content \.article-meta\s*\{[^}]*font-size:\s*0\.8125rem/s',
            $css,
        );
    }

    private function css(): string
    {
        $css = file_get_contents(dirname(__DIR__) . '/themes/folio/assets/theme.css');
        self::assertIsString($css);

        return $css;
    }

    private function rawToken(string $css, string $name): string
    {
        $found = preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $match);
        self::assertSame(1, $found, $name . ' is declared');

        return trim($match[1]);
    }
}
