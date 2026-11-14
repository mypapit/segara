<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The fourth theme exists to carry DESIGN-4 ("Warm Scholarly Modernism").
 * These assertions pin the parts of that design a later restyle could quietly
 * drop: the palette values themselves, the type pairing, the glassmorphic bar,
 * the reading well, the two signature gestures, and the one deliberate
 * deviation (terracotta buttons carry espresso ink, not white, because white
 * on #F18222 measures 2.64:1 and fails WCAG AA).
 */
final class ScholarThemeTest extends TestCase
{
    public function testThePaletteComesFromTheDesignFile(): void
    {
        $css = $this->css();

        $expected = [
            '--canvas' => '#FFFEFE',
            '--surface' => '#FAF7F4',
            '--on-surface' => '#362517',
            '--primary' => '#954A00',
            '--secondary' => '#F18222',
            '--accent' => '#9A5514',
            '--border' => '#E9E9E9',
            '--accent-soft' => '#FFEAD5',
            '--header-line' => '#E9E9E9',
            '--footer-bg' => '#FFEAD5',
            '--footer-ink' => '#362517',
            '--footer-accent' => '#9A5514',
        ];

        foreach ($expected as $token => $value) {
            self::assertSame(
                $value,
                $this->token($css, $token),
                $token . ' must stay the value DESIGN-4 specifies'
            );
        }
    }

    public function testTheTypePairingIsSerifHeadingsAndSansBody(): void
    {
        $css = $this->css();

        self::assertStringContainsString('"Source Serif 4"', $this->rawToken($css, '--font-head'));
        self::assertStringContainsString('Georgia', $this->rawToken($css, '--font-head'));
        self::assertStringContainsString('"Work Sans"', $this->rawToken($css, '--font-body'));
        self::assertMatchesRegularExpression('/\bh1\b[^{]*\{[^}]*font-family:\s*var\(--font-head\)/s', $css);
    }

    public function testTheHeaderIsTheGlassmorphicBarFromTheDesign(): void
    {
        $css = $this->css();

        self::assertSame('rgba(255, 254, 254, 0.88)', $this->rawToken($css, '--header-bg'));
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*position:\s*sticky/s', $css);
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*backdrop-filter:\s*blur\(12px\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*background:\s*var\(--header-bg\)/s', $css);
        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*border-bottom:\s*1px solid var\(--header-line\)/s', $css);
    }

    public function testEveryTextPairingPassesWcagAa(): void
    {
        $css = $this->css();

        // 4.5 is the AA floor for body text; a scholarly reading surface is held
        // to AAA (7.0) for its two inks.
        self::assertGreaterThanOrEqual(7.0, $this->contrast($this->token($css, '--footer-bg'), $this->token($css, '--footer-ink')), 'ink on peach');
        self::assertGreaterThanOrEqual(7.0, $this->contrast($this->token($css, '--canvas'), $this->token($css, '--on-surface')), 'ink on canvas');
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, '--canvas'), $this->token($css, '--primary')), 'primary ink on canvas');
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, '--footer-bg'), $this->token($css, '--footer-accent')), 'bronze on peach');
    }

    public function testThePrimaryButtonKeepsTheTerracottaFillButNotWhiteText(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.button\s*\{[^}]*background:\s*var\(--secondary\)/s', $css);
        self::assertMatchesRegularExpression('/\.button\s*\{[^}]*color:\s*#281809/s', $css);

        // White on #F18222 is 2.64:1. If a later edit reintroduces it, the
        // button silently becomes unreadable.
        self::assertDoesNotMatchRegularExpression(
            '/\.button[^{]*\{[^}]*color:\s*#f{3,6}\b/is',
            $css,
            'terracotta buttons must not carry white text'
        );
        self::assertGreaterThanOrEqual(4.5, $this->contrast('#F18222', '#281809'), 'button label on terracotta');
    }

    public function testTheTwoSignatureGesturesArePresent(): void
    {
        $css = $this->css();

        // "Signature Asymmetric Tab (border-radius: 0 48px 0 0)".
        self::assertStringContainsString('border-radius: 0 48px 0 0', $css);
        // "Pull Quotes: left-bordered with a 3px vertical rule in #F18222".
        self::assertMatchesRegularExpression(
            '/\.page-content blockquote\s*\{[^}]*border-left:\s*3px solid var\(--secondary\)/s',
            $css
        );
        self::assertMatchesRegularExpression('/\.page-content blockquote\s*\{[^}]*font-family:\s*var\(--font-head\)/s', $css);
        self::assertMatchesRegularExpression('/\.page-content blockquote\s*\{[^}]*font-style:\s*italic/s', $css);
        self::assertMatchesRegularExpression('/\.page-content cite\s*\{[^}]*font-style:\s*italic/s', $css);
    }

    public function testTheArticleSitsInASevenHundredAndTwentyPixelWell(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.page-content\s*\{[^}]*max-width:\s*720px/s', $css);
        // The 1400px column is a token now, shared with the header row.
        self::assertMatchesRegularExpression('/\.layout\s*\{[^}]*max-width:\s*var\(--content-max\)/s', $css);
        self::assertStringContainsString('--content-max: 1400px;', $css);
    }

    public function testTheStylesheetStaysSelfContainedAndCspClean(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('@import', strtolower($css));
        self::assertStringNotContainsString('url(http', strtolower($css));
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'), 'braces must balance');

        foreach (['layout.php', 'page.php', 'home.php', 'error.php', '500.php'] as $template) {
            $php = (string) file_get_contents($this->root() . '/themes/scholar/' . $template);
            self::assertStringNotContainsString('style="', $php, $template . ' must not use inline styles (CSP)');
            self::assertStringNotContainsString('://', $php, $template . ' must not link an external asset');
        }
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }

    private function css(): string
    {
        $css = file_get_contents($this->root() . '/themes/scholar/assets/theme.css');
        self::assertIsString($css);

        return $css;
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

    /** WCAG 2.1 relative luminance contrast, 1..21. */
    private function contrast(string $background, string $foreground): float
    {
        $luminance = static function (string $hex): float {
            $channels = [];
            foreach ([1, 3, 5] as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($background);
        $b = $luminance($foreground);
        $light = max($a, $b);
        $dark = min($a, $b);

        return ($light + 0.05) / ($dark + 0.05);
    }
}
