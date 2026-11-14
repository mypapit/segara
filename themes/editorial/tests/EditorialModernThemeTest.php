<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class EditorialModernThemeTest extends TestCase
{
    public function testTwoSidebarIdentityAndBreakpointFitArePreserved(): void
    {
        $layout = $this->template('layout.php');
        self::assertStringContainsString('sidebar sidebar--left', $layout);
        self::assertStringContainsString('sidebar sidebar--right', $layout);
        self::assertLessThan(strpos($layout, 'sidebar--right'), strpos($layout, '<main id="main"'));
        $css = $this->css();
        self::assertStringContainsString('.layout:has(.sidebar--left) { grid-template-columns: 220px minmax(0, 720px);', $css);
        self::assertStringContainsString('.layout:has(.sidebar--right) { grid-template-columns: minmax(0, 720px) 260px;', $css);
        self::assertStringContainsString('.layout:has(.sidebar--left):has(.sidebar--right) { grid-template-columns: 220px minmax(0, 720px) 260px; }', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 1280px\)[\s\S]*?\.layout\s*\{[^}]*column-gap:\s*0\.5rem/s', $css);
        self::assertStringContainsString('--content-max: 1280px;', $css);
        self::assertSame(2, substr_count($css, 'justify-content: start;'));
    }

    public function testHomeHasAnEditorialMastheadAndRichCards(): void
    {
        $home = $this->template('home.php');
        self::assertStringContainsString("require __DIR__ . '/partials/masthead.php'", $home);
        self::assertStringContainsString("require __DIR__ . '/partials/page-card.php'", $home);
        self::assertStringNotContainsString('shared/page-card.php', $home);
        $masthead = $this->template('partials/masthead.php');
        self::assertStringContainsString('class="masthead__stats"', $masthead);
        self::assertStringContainsString('gmdate(\'F Y\', $latest)', $masthead);
        $card = $this->template('partials/page-card.php');
        self::assertStringContainsString('class="page-card__date"', $card);
        self::assertStringContainsString('class="page-card__tags"', $card);
        self::assertStringContainsString('class="page-card__more"', $card);
        self::assertStringContainsString('Html::escape($page[\'title\'])', $card);
        self::assertStringContainsString('Html::escape($tag)', $card);
        self::assertStringContainsString('href="#s-arrow"', $card);
    }

    public function testLongTagsWrapAndDecorativeReadIsHidden(): void
    {
        $card = $this->template('partials/page-card.php');
        self::assertStringContainsString('class="page-card__more" aria-hidden="true"', $card);
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.page-card__tags\s*\{[^}]*min-width:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.page-card__tag\s*\{[^}]*max-width:\s*100%/s', $css);
        self::assertMatchesRegularExpression('/\.page-card__tag\s*\{[^}]*overflow-wrap:\s*anywhere/s', $css);
    }

    public function testCardsUseEditorialDepthAndOneLink(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*position:\s*relative/s', $css);
        self::assertMatchesRegularExpression('/\.page-card\s*\{[^}]*box-shadow:\s*var\(--shadow-1\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-card__title a::after\s*\{[^}]*inset:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.page-card--featured\s*\{[^}]*border-top:\s*3px solid var\(--secondary\)/s', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testArticleHasReadingHierarchyAndSafeDropCap(): void
    {
        $page = $this->template('page.php');
        self::assertStringContainsString('class="article-head"', $page);
        self::assertStringContainsString('$minutes = max(1, (int) ceil($words / 220));', $page);
        self::assertStringContainsString('href="#s-clock"', $page);
        self::assertStringContainsString("require __DIR__ . '/../shared/page-date.php'", $page);
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.article-head\s*\{[^}]*border-bottom:\s*1px solid var\(--border\)/s', $css);
        self::assertStringContainsString('.page-content > .article-head + p::first-letter', $css);
        self::assertMatchesRegularExpression('/\.page-content > p:not\(\.page-meta\),[\s\S]*?line-height:\s*1\.75/s', $css);
    }

    public function testSidebarsUseOnePaddedRailInsteadOfStackedCards(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/\.sidebar\s*\{[^}]*gap:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar\s*\{[^}]*align-self:\s*start/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar\s*\{[^}]*padding:\s*1\.125rem/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar--left\s*\{[^}]*border-top:\s*2px solid rgb\(217 119 6 \/ 0\.55\)/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar--right\s*\{[^}]*border-top:\s*2px solid rgb\(79 70 229 \/ 0\.45\)/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar \.block\s*\{[^}]*padding:\s*0 0 1rem/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar \.block\s*\{[^}]*background:\s*transparent/s', $css);
        self::assertMatchesRegularExpression('/\.sidebar \.block\s*\{[^}]*border-bottom:\s*1px solid var\(--border-soft\)/s', $css);
        self::assertStringContainsString('.sidebar .block:last-child', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 1360px\)[\s\S]*?\.layout\s*\{[^}]*column-gap:\s*1rem/s', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 1280px\)[\s\S]*?\.sidebar--right\s*\{[^}]*position:\s*sticky/s', $css);
    }

    public function testShellEmptyStateAndChallengeAreFinished(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/body\s*\{[^}]*display:\s*flex/s', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*min-height:\s*100svh/s', $css);
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*flex:\s*1 0 auto/s', $css);
        self::assertMatchesRegularExpression('/\.site-footer\s*\{[^}]*flex-shrink:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.site-title\s*\{[^}]*font-family:\s*var\(--font-head\)/s', $css);
        self::assertStringContainsString('class="icon empty-state__icon"', $this->template('home.php'));
        self::assertStringContainsString('class="challenge__lock"', $this->template('page.php'));
        self::assertMatchesRegularExpression('/\.challenge\s*\{[^}]*box-shadow:\s*var\(--shadow-1\)/s', $css);
    }

    public function testPaletteContrastAndCspHygiene(): void
    {
        foreach ([['#1a1c1a', '#faf9f6'], ['#4f46e5', '#faf9f6'], ['#f2f1ee', '#2f312f'], ['#fbbf24', '#2f312f'], ['#faf9f6', '#111827']] as [$ink, $bg]) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($ink, $bg), "$ink on $bg");
        }
        self::assertSame('#d97706', $this->token('--secondary'));
        self::assertSame('#fbbf24', $this->token('--footer-link'));
        self::assertStringNotContainsString('@import', strtolower($this->css()));
        self::assertStringNotContainsString('url(http', strtolower($this->css()));
        self::assertSame(substr_count($this->css(), '{'), substr_count($this->css(), '}'));
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
            $channel = static fn(float $v): float => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            return 0.2126 * $channel(hexdec(substr($hex, 1, 2)) / 255)
                + 0.7152 * $channel(hexdec(substr($hex, 3, 2)) / 255)
                + 0.0722 * $channel(hexdec(substr($hex, 5, 2)) / 255);
        };
        $high = max($luminance($a), $luminance($b));
        $low = min($luminance($a), $luminance($b));
        return ($high + 0.05) / ($low + 0.05);
    }
}
