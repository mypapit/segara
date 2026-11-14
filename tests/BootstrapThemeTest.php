<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Segara\Http\SecurityHeaders;
use Segara\View\Themes;

/**
 * Slate is the Bootstrap 5.3 theme: Bootstrap from a pinned CDN, a black navbar
 * with white links, a blue gradient jumbotron carrying the site name, the site
 * tagline and the description in white, card content, a dark footer, no sidebar.
 */
final class BootstrapThemeTest extends TestCase
{
    private const CDN = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
    private const SRI = 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH';

    public function testTheDescriptorDeclaresNoSidebar(): void
    {
        $themes = new Themes(dirname(__DIR__) . '/themes');
        $info = $themes->info('slate');

        self::assertSame('Slate Bootstrap', $info['name']);
        self::assertSame(0, $info['sidebars']);
        self::assertSame('#ffffff', $info['swatch']['surface']);
        self::assertSame('#0a58ca', $info['swatch']['primary']);
    }

    public function testBootstrapComesFromThePinnedCdnBeforeTheTheme(): void
    {
        $layout = $this->template('layout.php');

        self::assertStringContainsString(self::CDN, $layout);
        self::assertStringContainsString('integrity="' . self::SRI . '"', $layout);
        self::assertStringContainsString('crossorigin="anonymous"', $layout);
        self::assertStringNotContainsString('bootstrap@latest', $layout, 'the version must be pinned');
        self::assertStringNotContainsString('bootstrap.bundle', $layout, 'no Bootstrap JS');

        $cdn = strpos($layout, self::CDN);
        $theme = strpos($layout, 'theme.css.php');
        self::assertIsInt($cdn);
        self::assertIsInt($theme);
        self::assertLessThan($theme, $cdn, 'Bootstrap loads first so the theme wins the cascade');
    }

    public function testTheCspAllowsOnlyThePinnedBootstrapDirectory(): void
    {
        self::assertStringContainsString(
            "style-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/",
            SecurityHeaders::CSP
        );
        self::assertStringNotContainsString("script-src 'self' https://cdn.jsdelivr.net", SecurityHeaders::CSP);
        self::assertStringNotContainsString(
            "style-src 'self' https://cdn.jsdelivr.net;",
            SecurityHeaders::CSP,
            'a host-wide source would be too broad'
        );
    }

    public function testTheNavbarIsWhiteOnBlack(): void
    {
        $css = $this->css();

        self::assertSame('#212529', $this->token($css, '--header-bg'));
        self::assertSame('#343a40', $this->token($css, '--header-line'));
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*background:\s*var\(--header-bg\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a\s*\{[^}]*color:\s*rgba\(255, 255, 255, 0\.85\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a:hover,[\s\S]*?\{[^}]*color:\s*#ffffff/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a:hover,[\s\S]*?\{[^}]*text-decoration:\s*underline/s', $css);
        // The collapsed sub-menu sits on the dark bar, not on a white panel, and
        // its links must not inherit --muted (2.53:1 on #212529).
        self::assertMatchesRegularExpression('/\.site-nav ul ul\s*\{[^}]*background:\s*#212529/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav ul ul a\s*\{[^}]*color:\s*#ced4da/s', $css);
        // The brand colour is kept for the shared partial even though this theme
        // suppresses the brand: if it is ever re-enabled it must not render dark
        // ink on a #212529 bar.
        self::assertMatchesRegularExpression('/\.site-title\s*\{[^}]*color:\s*#ffffff/s', $css);
    }

    public function testTheNavbarIsShortAndCarriesOnlyTheMenu(): void
    {
        $layout = $this->template('layout.php');
        $header = (string) file_get_contents(dirname(__DIR__) . '/themes/shared/header.php');
        $css = $this->css();

        // The theme opts out of the brand, and does it before the partial runs.
        self::assertStringContainsString('$showBrand = false;', $layout);
        $optOut = strpos($layout, '$showBrand = false;');
        $partial = strpos($layout, 'shared/header.php');
        self::assertIsInt($optOut);
        self::assertIsInt($partial);
        self::assertLessThan($partial, $optOut, 'the opt-out is set before the header partial is included');

        // The shared partial honours the flag and stays brand-first for the rest.
        self::assertStringContainsString('$showBrand ?? true', $header);
        self::assertStringContainsString('site-header--nav-only', $header);

        // 0.3rem of vertical padding on a 0.875rem link with 0.25rem of its own:
        // about 40px tall, against the 68px bar this replaces.
        self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*padding:\s*0\.3rem var\(--content-pad\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a\s*\{[^}]*font-size:\s*0\.875rem/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a\s*\{[^}]*padding:\s*0\.25rem 0/s', $css);
        // With no brand in the row the menu has to be pushed right by the row itself.
        self::assertMatchesRegularExpression('/\.site-header--nav-only \.header-inner\s*\{[^}]*justify-content:\s*flex-end/s', $css);
    }

    public function testTheJumbotronSpansTheViewport(): void
    {
        $layout = $this->template('layout.php');
        $css = $this->css();

        // Emitted above the content column, so nothing constrains its width.
        $bandAt = strpos($layout, '<section class="jumbotron">');
        $columnAt = strpos($layout, '<div class="layout">');
        // The closing tag lives in the shared partial; the include is the marker.
        $barAt = strpos($layout, 'shared/header.php');
        self::assertIsInt($bandAt);
        self::assertIsInt($columnAt);
        self::assertIsInt($barAt);
        self::assertLessThan($columnAt, $bandAt, 'the band is a sibling above .layout, not a child of it');
        self::assertGreaterThan($barAt, $bandAt, 'the band follows the bar');

        // Full-bleed: no radius, no side padding, no column width of its own.
        $found = preg_match('/\.jumbotron\s*\{[^}]*\}/s', $css, $rule);
        self::assertSame(1, $found);
        self::assertStringNotContainsString('border-radius', $rule[0]);
        self::assertStringNotContainsString('max-width', $rule[0]);
        self::assertMatchesRegularExpression('/\.jumbotron\s*\{[^}]*padding:\s*4rem 0/s', $css);
        // The column moves to the inner wrapper, so the text still lines up.
        self::assertMatchesRegularExpression('/\.jumbotron__inner\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron__inner\s*\{[^}]*padding:\s*0 var\(--content-pad\)/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron__inner\s*\{[^}]*margin:\s*0 auto/s', $css);
        // Content below the band needs its own breathing room now.
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*padding:\s*2\.5rem var\(--content-pad\) 3rem/s', $css);
    }

    public function testTheJumbotronCarriesTheIdentityOnTheHomePageOnly(): void
    {
        $layout = $this->template('layout.php');
        $home = $this->template('home.php');

        self::assertStringContainsString('if ($isHome)', $layout, 'the band is gated to the home page');
        self::assertStringContainsString('Html::escape($siteName)', $layout);
        self::assertStringContainsString("if (\$site_tagline !== '')", $layout, 'the tagline sits under the site name');
        self::assertStringContainsString('Html::escape($site_tagline)', $layout);
        self::assertStringContainsString('Html::escape($description)', $layout);
        // The band moved out of the page body.
        self::assertStringNotContainsString('class="jumbotron"', $home);

        $css = $this->css();
        self::assertSame('#0a58ca', $this->token($css, '--jumbo-start'));
        self::assertSame('#084298', $this->token($css, '--jumbo-mid'));
        self::assertSame('#052c65', $this->token($css, '--jumbo-end'));
        self::assertMatchesRegularExpression(
            '/\.jumbotron\s*\{[^}]*background-image:\s*linear-gradient\(135deg,\s*var\(--jumbo-start\)[^;]*var\(--jumbo-mid\)[^;]*var\(--jumbo-end\)/s',
            $css
        );
        self::assertMatchesRegularExpression('/\.jumbotron\s*\{[^}]*color:\s*#ffffff/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron\s*\{[^}]*background-color:\s*var\(--jumbo-end\)/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron__title\s*\{[^}]*font-weight:\s*300/s', $css);
        // The tagline is a short line under the name, much smaller than it: 16px
        // against a 48px title at desktop. The lead stays 20px because it is a
        // paragraph, not a kicker.
        self::assertMatchesRegularExpression('/\.jumbotron__tagline\s*\{[^}]*font-size:\s*1rem/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron__tagline\s*\{[^}]*letter-spacing:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron__lead\s*\{[^}]*font-size:\s*1\.25rem/s', $css);
        // White on these two Bootstrap blues fails AA, so neither may become a
        // gradient stop. Scoped to the band: #6ea8fe is the footer link colour.
        $scoped = preg_match('/\.jumbotron\s*\{[^}]*\}/s', $css, $bandRule);
        self::assertSame(1, $scoped);
        self::assertStringNotContainsString('#3d8bfd', $bandRule[0]);
        self::assertStringNotContainsString('#6ea8fe', $bandRule[0]);
    }

    public function testTheFooterIsTheDarkBandBootstrapClosesWith(): void
    {
        $css = $this->css();

        self::assertSame('#212529', $this->token($css, '--footer-bg'));
        self::assertSame('#dee2e6', $this->token($css, '--footer-ink'));
        self::assertSame('#6ea8fe', $this->token($css, '--footer-accent'));
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*border-top:[^;]*var\(--footer-accent\)/s', $css);
    }

    public function testThePageKeepsBootstrapSTypeScale(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-size:\s*1rem/s', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*line-height:\s*1\.5/s', $css);
        self::assertMatchesRegularExpression('/h1, h2, h3\s*\{[^}]*font-weight:\s*500/s', $css);
        self::assertMatchesRegularExpression('/\.page-content h1\s*\{[^}]*font-size:\s*2\.5rem/s', $css);
        self::assertMatchesRegularExpression('/\.button\s*\{[^}]*border-radius:\s*0\.375rem/s', $css);
    }

    public function testTheHeaderSharesTheContentColumn(): void
    {
        $css = $this->css();

        self::assertSame('1140px', $this->rawToken($css, '--content-max'));
        self::assertSame('0.75rem', $this->rawToken($css, '--content-pad'));
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertMatchesRegularExpression('/\.header-inner\s*\{[^}]*justify-content:\s*space-between/s', $css);
        // Bootstrap's navbar-expand-lg breakpoint.
        self::assertStringNotContainsString('760px', $css);
        self::assertStringContainsString('@media (min-width: 992px)', $css);
    }

    public function testNoSidebarIsRendered(): void
    {
        $layout = $this->template('layout.php');
        $css = $this->css();

        self::assertStringContainsString('$sidebars >= 1', $layout, 'the aside branches stay guarded');
        self::assertStringNotContainsString('sidebar--rail', $layout);
        self::assertStringNotContainsString('.sidebar--rail', $css);
        self::assertStringNotContainsString('.sidebar--left', $css);
    }

    public function testTheSharedContractsCameAlong(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.block--facts li[data-bullet]::before', $css);
        self::assertStringContainsString('.rss-chicklet', $css);
        self::assertStringContainsString('.contact-form button', $css);
        self::assertStringNotContainsString('@import', strtolower($css), 'the CDN lives in the head, not in @import');
        self::assertStringNotContainsString('style="', $this->template('home.php'));
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'), 'braces must balance');
    }

    private function css(): string
    {
        return $this->template('assets/theme.css');
    }

    private function template(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/themes/slate/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    private function token(string $css, string $name): string
    {
        $found = preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})/i', $css, $match);
        self::assertSame(1, $found, $name . ' is a hex token');

        return $match[1];
    }

    private function rawToken(string $css, string $name): string
    {
        $found = preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $match);
        self::assertSame(1, $found, $name . ' is declared');

        return trim($match[1]);
    }
}
