<?php

declare(strict_types=1);

namespace Tests;

use Segara\Config;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

final class ThemeIntegrationTest extends TestCase
{
    public function testActiveThemeDefaultsToClarityWithoutSettings(): void
    {
        self::assertSame('clarity', (new Renderer($this->config()))->activeTheme());
    }

    public function testActiveThemeComesFromSavedSettings(): void
    {
        $settings = $this->settings();
        $settings->setMany(['theme' => 'aubergine']);

        self::assertSame('aubergine', (new Renderer($this->config(), null, $settings))->activeTheme());
    }

    public function testConfiguredThemeIsAppliedToRenderedPage(): void
    {
        $settings = $this->settings();
        $settings->setMany(['theme' => 'editorial']);

        $renderer = new Renderer($this->config(), null, $settings);
        $html = $renderer->render($renderer->activeTheme(), 'page', [
            'title' => 'Hello',
            'body' => '<p>Hi</p>',
            'csrfToken' => '',
            'challenge' => false,
        ]);

        self::assertStringContainsString('data-theme="editorial"', $html);
        self::assertStringContainsString('theme.css.php?theme=editorial', $html);
    }

    public function testUnknownStoredThemeFallsBackToClarity(): void
    {
        $settings = $this->settings();
        // Simulate store drift the settings form would normally reject.
        $settings->setMany(['theme' => 'bogus']);

        self::assertSame('clarity', (new Renderer($this->config(), null, $settings))->activeTheme());
    }

    public function testThemeStylesheetUrlCarriesACacheBustingVersion(): void
    {
        $settings = $this->settings();
        $settings->setMany(['theme' => 'aubergine']);

        $renderer = new Renderer($this->config(), null, $settings);
        $html = $renderer->render($renderer->activeTheme(), 'page', [
            'title' => 'Hello',
            'body' => '<p>Hi</p>',
            'csrfToken' => '',
            'challenge' => false,
        ]);

        // The ampersand is HTML-escaped in the attribute; browsers decode it.
        self::assertMatchesRegularExpression(
            '#theme\.css\.php\?theme=aubergine&amp;v=\d+#',
            $html,
            'a deploy must not leave the cached stylesheet behind'
        );
    }

    private function settings(): SettingsRepository
    {
        $pdo = Database::connect($this->sqlitePath);
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();

        return new SettingsRepository($pdo);
    }

    private function config(): Config
    {
        return Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
    }
}
