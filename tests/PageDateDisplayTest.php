<?php

declare(strict_types=1);

namespace Tests;

use Segara\Config;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

/**
 * The date reaches the article, in the right place, in the format Settings says,
 * and never appears where there is no page to date.
 */
final class PageDateDisplayTest extends TestCase
{
    private const THEMES = ['clarity', 'aubergine', 'editorial', 'folio', 'scholar', 'slate'];
    private const PUBLISHED = '2026-10-25T01:17:38Z';

    private SettingsRepository $settings;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connect($this->sqlitePath);
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->settings = new SettingsRepository($pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
    }

    public function testTheDefaultFormatRendersInEveryTheme(): void
    {
        foreach (self::THEMES as $theme) {
            $html = $this->page($theme);

            self::assertStringContainsString('class="page-meta"', $html, $theme . ' renders the date block');
            self::assertStringContainsString(
                '<time class="page-meta__date" datetime="2026-10-25">October 25, 2026 (Sunday)</time>',
                $html,
                $theme . ' renders the default format',
            );
        }
    }

    public function testTheDateSitsAfterTheBodyInsideTheArticle(): void
    {
        $html = $this->page('clarity');

        $article = strpos($html, '<article class="page-content">');
        $body = strpos($html, 'Body marker');
        $date = strpos($html, 'class="page-meta"');
        $articleEnd = strpos($html, '</article>');
        self::assertIsInt($article);
        self::assertIsInt($body);
        self::assertIsInt($date);
        self::assertIsInt($articleEnd);
        self::assertLessThan($body, $article);
        self::assertLessThan($date, $body, 'the date follows the article body');
        self::assertLessThan($articleEnd, $date, 'and stays inside the article');
    }

    public function testThePlainFormatDropsTheWeekday(): void
    {
        $this->settings->setMany(['page_date_format' => 'plain']);

        $html = $this->page('clarity');

        self::assertStringContainsString('>October 25, 2026</time>', $html);
        self::assertStringNotContainsString('Sunday', $html);
    }

    public function testACustomPatternIsHonoured(): void
    {
        $this->settings->setMany(['page_date_format' => 'custom', 'page_date_pattern' => 'j F Y']);

        $html = $this->page('clarity');

        self::assertStringContainsString('>25 October 2026</time>', $html);
    }

    public function testRelativeModeKeepsTheMachineReadableDate(): void
    {
        $this->settings->setMany(['page_date_format' => 'relative']);

        $html = $this->page('clarity');

        self::assertStringContainsString('datetime="2026-10-25"', $html);
        self::assertMatchesRegularExpression('/>((just now)|(\d+ (minute|hour|day|week|month|year)s? ago))<\/time>/', $html);
    }

    public function testNoDateRendersWhenThePageHasNone(): void
    {
        self::assertStringNotContainsString('page-meta', $this->page('clarity', []));
    }

    public function testTheErrorPageCarriesNoDate(): void
    {
        $html = (new Renderer($this->config, null, $this->settings))->render('clarity', 'error');

        self::assertStringNotContainsString('page-meta', $html);
    }

    public function testABrokenStoredPatternDoesNotTakeThePageDown(): void
    {
        $this->settings->setMany(['page_date_format' => 'custom', 'page_date_pattern' => 'hello']);

        $html = $this->page('clarity');

        self::assertStringContainsString('October 25, 2026 (Sunday)', $html, 'it falls back to the default');
    }

    /** @param array<string, mixed> $extra */
    private function page(string $theme, array $extra = ['page_published_at' => self::PUBLISHED]): string
    {
        return (new Renderer($this->config, null, $this->settings))->render($theme, 'page', array_merge([
            'title' => 'A page',
            'body' => '<p>Body marker</p>',
            'challenge' => false,
            'challengeError' => null,
            'csrfToken' => '',
            'path' => '/about',
        ], $extra));
    }
}
