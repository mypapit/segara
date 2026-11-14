<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class SlateModernThemeTest extends TestCase
{
    public function testHomeUsesStructuredCollectionAndRichCards(): void
    {
        $home = $this->template('home.php');
        self::assertStringContainsString('class="collection-head"', $home);
        self::assertStringContainsString("require __DIR__ . '/partials/page-card.php'", $home);
        self::assertStringNotContainsString('shared/page-card.php', $home);
        $card = $this->template('partials/page-card.php');
        self::assertStringContainsString('class="page-card__date"', $card);
        self::assertStringContainsString('class="page-card__tags"', $card);
        self::assertStringContainsString('class="page-card__more"', $card);
        self::assertStringContainsString('Html::escape($page[\'title\'])', $card);
        self::assertStringContainsString('Html::escape($tag)', $card);
        self::assertStringContainsString('href="#s-arrow"', $card);
    }

    public function testCardCatalogIsResponsiveAndInteractive(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.page-stream\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fill, minmax\(min\(100%, 20rem\), 1fr\)\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*box-shadow:\s*var\(--shadow-1\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-card__title a::after\s*\{[^}]*inset:\s*0/s', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 768px\)[\s\S]*?\.page-card--featured\s*\{[^}]*grid-column:\s*span 2/s', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testArticleHasReadingKickerAndRefinedTypography(): void
    {
        $page = $this->template('page.php');
        self::assertStringContainsString('class="article-head"', $page);
        self::assertStringContainsString('$minutes = max(1, (int) ceil($words / 220));', $page);
        self::assertStringContainsString('href="#s-clock"', $page);
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.page-content\s*\{[^}]*margin-inline:\s*auto/s', $css);
        self::assertMatchesRegularExpression('/\.article-head\s*\{[^}]*border-bottom:\s*1px solid var\(--border\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-content > p:not\(\.page-meta\),[\s\S]*?font-size:\s*1\.125rem/s', $css);
    }

    public function testShortPagesUseStickyFooterAndAlignedBands(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/body\s*\{[^}]*display:\s*flex/s', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*min-height:\s*100svh/s', $css);
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*flex:\s*1 0 auto/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*flex-shrink:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer__blocks\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer__copyright\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
    }

    public function testSignatureSurfacesGainDepthWithoutChangingPalette(): void
    {
        $css = $this->css();
        self::assertSame('#0a58ca', $this->token('--jumbo-start'));
        self::assertSame('#084298', $this->token('--jumbo-mid'));
        self::assertSame('#052c65', $this->token('--jumbo-end'));
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*box-shadow:/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron\s*\{[^}]*position:\s*relative/s', $css);
        self::assertMatchesRegularExpression('/\.jumbotron\s*\{[^}]*overflow:\s*hidden/s', $css);
        self::assertStringContainsString('.jumbotron::before', $css);
    }

    public function testEmptyStateAndChallengeAreDesigned(): void
    {
        self::assertStringContainsString('class="icon empty-state__icon"', $this->template('home.php'));
        self::assertStringContainsString('class="challenge__lock"', $this->template('page.php'));
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.empty-state\s*\{[^}]*border:\s*1px dashed var\(--border\)/s', $css);
        self::assertMatchesRegularExpression('/\.challenge\s*\{[^}]*box-shadow:\s*var\(--shadow-1\)/s', $css);
    }

    public function testExistingContrastPairsStayAboveAa(): void
    {
        foreach ([['#ffffff', '#0a58ca'], ['#ffffff', '#084298'], ['#ffffff', '#052c65'], ['#5c636a', '#ffffff'], ['#dee2e6', '#212529'], ['#6ea8fe', '#212529'], ['#124e7c', '#e7f1ff']] as [$ink, $bg]) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($ink, $bg), "$ink on $bg");
        }
        self::assertSame(substr_count($this->css(), '{'), substr_count($this->css(), '}'));
        self::assertStringNotContainsString('@import', strtolower($this->css()));
    }

    private function template(string $name): string
    {
        $value = file_get_contents(dirname(__DIR__) . '/' . $name);
        self::assertIsString($value);
        return $value;
    }

    private function css(): string
    {
        return $this->template('assets/theme.css');
    }

    private function token(string $name): string
    {
        $found = preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})/i', $this->css(), $match);
        self::assertSame(1, $found);
        return $match[1];
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channel = static function (float $value): float {
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            };
            $r = $channel(hexdec(substr($hex, 1, 2)) / 255);
            $g = $channel(hexdec(substr($hex, 3, 2)) / 255);
            $b = $channel(hexdec(substr($hex, 5, 2)) / 255);
            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        $high = max($luminance($a), $luminance($b));
        $low = min($luminance($a), $luminance($b));
        return ($high + 0.05) / ($low + 0.05);
    }
}
