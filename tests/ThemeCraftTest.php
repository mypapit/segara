<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Craft contracts every theme owes the reader, whatever its palette.
 *
 * Each of these was a measured defect on the running site, not a preference:
 * a text field whose edge measured 1.2-1.4:1 against its own card, a single
 * unbroken token in a title widening the page by up to 660px at a 390px
 * viewport, and a small caps label sitting between the reader and the heading.
 * Themes are separate worlds; these three are not negotiable inside any of them.
 */
final class ThemeCraftTest extends TestCase
{
    private const THEMES = ['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'];

    /** Themes whose article header shows a reading time below the title. */
    private const READING_TIME_THEMES = ['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'];

    public function testEveryThemeDrawsItsFieldEdgeAtThreeToOne(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression(
                '/--outline:\s*#[0-9a-f]{6}/i',
                $css,
                $theme . ' declares the outline the palette needs but never carried'
            );
            self::assertMatchesRegularExpression(
                '/\.contact-form input\[type="text"\][\s\S]*?border:\s*1px solid var\(--outline\)/s',
                $css,
                $theme . ' draws the field edge in --outline, not in the hairline --border'
            );

            $ratio = $this->contrast(
                $this->resolve($css, 'var(--outline)'),
                $this->resolve($css, 'var(--canvas)'),
            );
            self::assertGreaterThanOrEqual(3.0, $ratio, $theme . ' field edge clears the 3:1 non-text minimum');
        }
    }

    public function testEveryThemeBreaksASingleLongToken(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);

            self::assertMatchesRegularExpression(
                '/\.page-card(__title)?[^{}]*\{[^}]*overflow-wrap:\s*anywhere/s',
                $css,
                $theme . ' lets a card title break instead of widening its track'
            );
            self::assertMatchesRegularExpression(
                '/\.page-content h1[^{}]*\{[^}]*overflow-wrap:\s*anywhere/s',
                $css,
                $theme . ' lets the article heading break'
            );
        }
    }

    public function testNoThemePutsALabelAboveAHeading(): void
    {
        foreach (self::THEMES as $theme) {
            foreach (['partials/masthead.php', 'page.php'] as $file) {
                $path = dirname(__DIR__) . '/themes/' . $theme . '/' . $file;
                if (!is_file($path)) {
                    continue;
                }

                $markup = (string) file_get_contents($path);
                self::assertStringNotContainsString('masthead__eyebrow', $markup, $theme . '/' . $file . ' keeps the heading free of a label');
                self::assertStringNotContainsString('article-kicker', $markup, $theme . '/' . $file . ' keeps the heading free of a kicker');
            }
        }
    }

    public function testTheReadingTimeSitsBelowItsTitle(): void
    {
        foreach (self::READING_TIME_THEMES as $theme) {
            $markup = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/page.php');

            // page.php renders two branches: the password challenge and the
            // article. Anchor on the article head, or the first </h1> in the
            // file is the challenge's and the assertion passes by accident.
            $head = strpos($markup, 'class="article-head"');
            self::assertIsInt($head, $theme . ' renders an article head');

            $heading = strpos($markup, '</h1>', $head);
            $readingTime = strpos($markup, 'article-meta', $head);
            self::assertIsInt($heading, $theme . ' renders the article heading');
            self::assertIsInt($readingTime, $theme . ' renders its reading time');
            self::assertLessThan($readingTime, $heading, $theme . ' puts the reading time after the heading');
        }

        // Every public theme now carries the same contract: six of six.
        foreach (self::THEMES as $theme) {
            self::assertContains($theme, self::READING_TIME_THEMES, $theme . ' is covered');
        }
    }

    private function css(string $theme): string
    {
        $css = file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
        self::assertIsString($css);

        return $css;
    }

    /** Resolve var(--x) to the hex token the theme declares. */
    private function resolve(string $css, string $value): string
    {
        if (preg_match('/var\(--([a-z-]+)\)/', $value, $match) === 1) {
            $found = preg_match('/--' . preg_quote($match[1], '/') . ':\s*(#[0-9a-f]{6})/i', $css, $hex);
            self::assertSame(1, $found, '--' . $match[1] . ' is a hex token');

            return $hex[1];
        }

        return $value;
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
