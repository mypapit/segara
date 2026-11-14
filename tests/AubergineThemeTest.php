<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Segara\Config;
use Segara\View\Renderer;

/**
 * Pins the 2026 restyle of Aubergine Atelier: the brand palette is unchanged,
 * the stream is a card grid, the header is a glass bar, the article keeps a
 * readable measure, and nothing is fetched from a third party.
 */
final class AubergineThemeTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/themes/aubergine/assets/theme.css');
    }

    private function token(string $css, string $name): string
    {
        if (preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $match) !== 1) {
            throw new RuntimeException($name . ' is not declared in theme.css');
        }

        return trim($match[1]);
    }

    /**
     * The opening was the one section of this theme quietly under its own
     * documented strength: the masthead title stopped at 3.2rem where the
     * Display role runs to 3.5rem, and the counts sat as two loose words.
     */
    public function testTheOpeningTakesTheDisplayRoleAtFullStrength(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.masthead__title\s*\{[^}]*font-size:\s*clamp\(2\.4rem, 5vw, 3\.5rem\)/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.masthead__title\s*\{[^}]*line-height:\s*1\.03/s', $css);
        self::assertMatchesRegularExpression('/\.masthead__title\s*\{[^}]*letter-spacing:\s*-0\.035em/s', $css);
        self::assertMatchesRegularExpression('/\.masthead__title\s*\{[^}]*max-width:\s*22ch/s', $css);
        // More air above the heading than below it.
        self::assertMatchesRegularExpression('/\.masthead\s*\{[^}]*padding:\s*3\.25rem 0 1\.1rem/s', $css);
    }

    public function testTheCountsSitInABandBetweenTwoHairlines(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.masthead__stats\s*\{[^}]*border-top:\s*1px solid var\(--border\)/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.masthead\s*\{[^}]*border-bottom:\s*1px solid var\(--border\)/s', $css);
        self::assertMatchesRegularExpression(
            '/\.masthead__stat \+ \.masthead__stat\s*\{[^}]*border-left:\s*1px solid var\(--border\)/s',
            $css,
        );
        // The band wraps on a phone, so the separator goes with the wrap.
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 479px\).{0,300}?\.masthead__stat \+ \.masthead__stat\s*\{\s*padding-left:\s*0;\s*border-left:\s*0;/s',
            $css,
        );
    }

    public function testTheBrandPaletteIsUnchanged(): void
    {
        $css = $this->css();
        $expected = [
            '--surface' => '#fcf8ff',
            '--primary' => '#231753',
            '--secondary' => '#634db4',
            '--accent' => '#ea580c',
            '--footer-bg' => '#231753',
            '--footer-ink' => '#f4eeff',
            '--footer-accent' => '#ea580c',
            '--violet-050' => '#f7f3ff',
        ];

        foreach ($expected as $token => $value) {
            self::assertSame($value, strtolower($this->token($css, $token)), $token . ' keeps the brand value');
        }
    }

    public function testTheHomeStreamIsAnAdaptiveCardGrid(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.page-stream\s*\{[^}]*display:\s*grid/s', $css);
        self::assertMatchesRegularExpression(
            '/\.page-stream\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fit, minmax\(16rem, 1fr\)\)/s',
            $css,
        );
    }

    public function testTheFirstEntryIsTheLeadAndFeaturedOnlyChangesItsSurface(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.page-card:first-child\s*\{[^}]*grid-column:\s*1 \/ -1/s', $css);
        self::assertMatchesRegularExpression(
            '/\.page-card--featured\s*\{[^}]*border-color:\s*var\(--border-strong\)/s',
            $css,
        );
        // A featured page always sorts first (PageRepository::listHomepage orders
        // placement first), so `:first-child` owns the track and a span rule here
        // could never apply. Keep the stylesheet free of it.
        self::assertStringNotContainsString('grid-column: span', $css);
    }

    public function testTheArticleKeepsAModernMeasureAndScale(): void
    {
        $css = $this->css();

        self::assertSame('66ch', $this->token($css, '--measure'));
        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-size:\s*17px/s', $css);
        self::assertMatchesRegularExpression('/\.page-content\s*\{[^}]*max-width:\s*var\(--measure\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-content h1\s*\{[^}]*font-size:\s*clamp\(/s', $css);
    }

    public function testTheHeaderIsAGlassBarWithAPillMenu(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression(
            '/\.site-header\s*\{[^}]*background:\s*var\(--header-bg\)[^}]*backdrop-filter:\s*blur\(/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.site-nav a\s*\{[^}]*border-radius:\s*999px/s', $css);
        self::assertMatchesRegularExpression('/\.site-nav a:hover[^{]*\{[^}]*border-bottom-color:/s', $css);
    }

    public function testTheFooterClosesWithAHairlineAccent(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*border-top:\s*1px solid var\(--footer-accent\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer__blocks\s*\{[^}]*display:\s*flex/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer \.block > h2\s*\{[^}]*font-weight:\s*700/s', $css);
    }

    public function testTheThemeCarriesItsOwnIconWork(): void
    {
        $css = $this->css();
        $page = (string) file_get_contents(dirname(__DIR__) . '/themes/aubergine/page.php');

        self::assertStringContainsString('class="challenge__lock"', $page);
        self::assertMatchesRegularExpression('/\.challenge__lock\s*\{[^}]*width:/s', $css);
        self::assertMatchesRegularExpression('/\.block--social a\s*\{[^}]*border-radius:/s', $css);
    }

    public function testTheStylesheetLoadsNothingRemote(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(http', $css);
        self::assertStringNotContainsString('cdn.', $css);
    }

    public function testBodyAndMutedTextPassContrast(): void
    {
        $css = $this->css();

        self::assertGreaterThanOrEqual(7.0, $this->contrast($this->token($css, '--on-surface'), $this->token($css, '--canvas')));
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, '--muted'), $this->token($css, '--canvas')));
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, '--footer-ink'), $this->token($css, '--footer-bg')));
    }

    public function testTheNotFoundPageIsACardWithAWayBack(): void
    {
        $html = (new Renderer(Config::fromArray(['base_url' => 'https://example.test'])))->render('aubergine', 'error');

        self::assertStringContainsString('class="not-found"', $html);
        self::assertStringContainsString('404 — Page not found', $html);
        self::assertStringContainsString('class="button"', $html);
    }

    public function testLongTokensWrapInsteadOfWideningThePage(): void
    {
        $css = $this->css();

        foreach (['.page-card__title', '.page-card__summary', '.page-card__tag', '.page-content h1'] as $selector) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\s*\{[^}]*overflow-wrap:\s*anywhere/s',
                $css,
                $selector . ' must break a single long token',
            );
        }
    }

    public function testTheFieldEdgeIsVisiblyDrawn(): void
    {
        $css = $this->css();

        self::assertGreaterThanOrEqual(
            3.0,
            $this->contrast($this->token($css, '--outline'), $this->token($css, '--canvas')),
            'a text field boundary needs 3:1 against the surface it sits on',
        );
        self::assertMatchesRegularExpression(
            '/\.contact-form input\[type="text"\][\s\S]*?border:\s*1px solid var\(--outline\)/s',
            $css,
        );
    }

    public function testNoLabelSitsAboveAHeadingAndTheReadingTimeFollowsItsTitle(): void
    {
        $masthead = (string) file_get_contents(dirname(__DIR__) . '/themes/aubergine/partials/masthead.php');
        $page = (string) file_get_contents(dirname(__DIR__) . '/themes/aubergine/page.php');

        self::assertStringNotContainsString('masthead__eyebrow', $masthead);
        self::assertStringNotContainsString('article-kicker', $page);

        $heading = strpos($page, '</h1>', (int) strpos($page, 'class="article-head"'));
        $readingTime = strpos($page, 'article-meta', (int) strpos($page, 'class="article-head"'));
        self::assertIsInt($heading);
        self::assertIsInt($readingTime);
        self::assertLessThan($readingTime, $heading, 'the reading time belongs below the heading, not above it');
    }

    public function testTheBrowserSurfacesAreThemed(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/html\s*\{[^}]*scrollbar-color:\s*var\(--violet-300\)/s', $css);
        self::assertMatchesRegularExpression('/\.contact-form input\[type="text"\][\s\S]*?caret-color:\s*var\(--primary\)/s', $css);
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channels = [];
            foreach ([0, 2, 4] as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $first = $luminance($a);
        $second = $luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }
}
