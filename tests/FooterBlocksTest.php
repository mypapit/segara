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
 * Footer block region: horizontal arrangement, single copyright, and the
 * per-type markup the requirements call for (address element, blurb image
 * with real alt text).
 */
final class FooterBlocksTest extends TestCase
{
    private ?PDO $pdo = null;

    public function testFooterBlocksRenderInsideTheFooterContainer(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('footer', 'address', ['text' => "1 Admin Street\nAlor Setar"]);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('class="site-footer__blocks"', $html);
        self::assertStringContainsString('1 Admin Street', $html);
    }

    public function testAnEmptyFooterRendersNoContainer(): void
    {
        $this->seedTheme('aubergine');

        self::assertStringNotContainsString('site-footer__blocks', $this->home()->index()->body());
    }

    public function testAuthoredCopyrightReplacesTheGeneratedLine(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('footer', 'copyright', ['text' => '© 2026 Alpha Authored']);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('© 2026 Alpha Authored', $html);
        self::assertStringNotContainsString('© 2026 Segara', $html);
        self::assertSame(1, substr_count($html, '©'));
    }

    public function testAddressUsesTheAddressElement(): void
    {
        $this->seedTheme('aubergine');
        $this->seedBlock('footer', 'address', ['text' => 'Somewhere']);

        self::assertStringContainsString('<address class="block block--address">', $this->home()->index()->body());
    }

    public function testBlurbRendersTheFeaturedImageWithItsAltText(): void
    {
        $this->seedTheme('aubergine');
        $mediaId = $this->seedMedia('Portrait of the author');
        $this->seedBlock('footer', 'blurb', [
            'title' => 'About',
            'text' => 'Short biography.',
            'image_id' => $mediaId,
        ]);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('alt="Portrait of the author"', $html);
        self::assertStringContainsString('block--blurb', $html);
    }

    public function testBlurbWithoutAltTextRendersNoImage(): void
    {
        $this->seedTheme('aubergine');
        $mediaId = $this->seedMedia('');
        $this->seedBlock('footer', 'blurb', ['title' => 'About', 'text' => 'Short biography.', 'image_id' => $mediaId]);

        $html = $this->home()->index()->body();

        self::assertStringContainsString('block--blurb', $html);
        self::assertStringNotContainsString('<img', $html, 'an image without a description must not be emitted');
    }

    public function testFooterBlocksArrangeHorizontallyAboveTheBreakpoint(): void
    {
        foreach (['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'] as $theme) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');
            self::assertStringContainsString('.site-footer__blocks {', $css, $theme . ' must arrange footer blocks');
            self::assertMatchesRegularExpression('/\.site-footer__blocks[^{]*\{[^}]*display:\s*flex/s', $css, $theme . ' must use a horizontal layout');
        }
    }

    public function testEveryThemeGivesTheFooterAStrongClosingBand(): void
    {
        foreach (['clarity', 'aubergine', 'editorial', 'scholar', 'folio', 'slate'] as $theme) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css');

            // The band is a token pair so a theme can restate it without a
            // second rule; DESIGN-1/2/3 each define an inverse surface.
            self::assertMatchesRegularExpression('/--footer-bg:\s*#[0-9a-f]{6}/i', $css, $theme . ' defines --footer-bg');
            self::assertMatchesRegularExpression('/--footer-ink:\s*#[0-9a-f]{6}/i', $css, $theme . ' defines --footer-ink');
            self::assertMatchesRegularExpression('/--footer-accent:\s*#[0-9a-f]{6}/i', $css, $theme . ' defines --footer-accent');

            self::assertMatchesRegularExpression(
                '/\.site-footer\s*\{[^}]*background:\s*var\(--footer-bg\)/s',
                $css,
                $theme . ' paints the footer band'
            );
            self::assertMatchesRegularExpression(
                '/\.site-footer\s*\{[^}]*border-top:\s*[^;]*var\(--footer-accent\)/s',
                $css,
                $theme . ' marks the top of the band with the accent'
            );
            // Bolder than the sidebar headings, which are the same size.
            self::assertMatchesRegularExpression(
                '/\.site-footer\s+\.block\s*>\s*h2\s*\{[^}]*font-weight:\s*(700|800|900)/s',
                $css,
                $theme . ' gives footer headings weight'
            );
            self::assertMatchesRegularExpression(
                '/\.site-footer__copyright\s*\{[^}]*border-top:/s',
                $css,
                $theme . ' separates the colophon line'
            );

            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($this->token($css, '--footer-bg'), $this->token($css, '--footer-ink')),
                $theme . ' keeps footer body text at WCAG AA'
            );
        }
    }

    private function token(string $css, string $name): string
    {
        $found = preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})/i', $css, $match);
        self::assertSame(1, $found, $name . ' is a hex token');

        return $match[1];
    }

    /** WCAG 2.1 relative luminance contrast, 1..21. */
    private function contrastRatio(string $background, string $foreground): float
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

    private function home(): HomeController
    {
        $renderer = new BlockRenderer($this->config(), new MenuRepository($this->pdo()), new MediaRepository($this->pdo()));

        return new HomeController(
            $this->config(),
            new PageRepository($this->pdo()),
            new Renderer($this->config(), null, new SettingsRepository($this->pdo())),
            new PublicBlocks(new BlockRepository($this->pdo()), $renderer),
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

    private function seedMedia(string $alt): string
    {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->pdo()->prepare(
            'INSERT INTO media (id, original_name, stored_path, mime, size, alt_text, decorative, created_at, updated_at) '
            . 'VALUES (:id, :name, :path, :mime, 0, :alt, 0, :now, :now)'
        );
        $statement->execute([
            'id' => $id,
            'name' => 'portrait.png',
            'path' => 'uploads/' . $id . '.png',
            'mime' => 'image/png',
            'alt' => $alt,
            'now' => $now,
        ]);

        return $id;
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
