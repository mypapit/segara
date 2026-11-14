<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use Segara\Config;
use Segara\Pages\PageRepository;
use Segara\PublicSite\HomeController;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

final class HomeTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;
    private HomeController $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
        $config = Config::fromArray(['base_url' => 'https://example.test']);
        $this->home = new HomeController($config, $this->pages, new Renderer($config));
    }

    public function testListHomepageCapsAtTwentyWithFeaturedFirstAndNewestWithinGroup(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createPublished('Featured ' . $i, 'featured', sprintf('2026-01-%02dT00:00:00Z', $i));
        }
        for ($i = 1; $i <= 15; $i++) {
            $this->createPublished('Home ' . $i, 'home', sprintf('2026-02-%02dT00:00:00Z', $i));
        }
        $this->pages->create(['title' => 'Draft Secret', 'state' => 'draft', 'author_name' => 'Admin']);
        $this->pages->create(['title' => 'Private Secret', 'state' => 'private', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'author_name' => 'Admin']);
        $this->pages->create(['title' => 'Trash Secret', 'state' => 'trash', 'author_name' => 'Admin']);

        $stream = $this->pages->listHomepage();

        self::assertCount(20, $stream);
        foreach ($stream as $page) {
            self::assertSame('published', $page['state']);
        }
        $featured = array_slice($stream, 0, 15);
        $home = array_slice($stream, 15, 5);
        foreach ($featured as $page) {
            self::assertSame('featured', $page['placement']);
        }
        foreach ($home as $page) {
            self::assertSame('home', $page['placement']);
        }
        self::assertSame('Featured 15', $featured[0]['title']);
        self::assertSame('Home 15', $home[0]['title']);
    }

    public function testHomeRendersStreamWithoutLeakageAndWithFeaturedBadge(): void
    {
        $this->createPublished('Featured Visible', 'featured', '2026-01-01T00:00:00Z');
        $this->createPublished('Home Visible', 'home', '2026-01-02T00:00:00Z');
        $this->pages->create(['title' => 'Draft Secret', 'state' => 'draft', 'author_name' => 'Admin']);
        $this->pages->create(['title' => 'Private Secret', 'state' => 'private', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'author_name' => 'Admin']);

        $response = $this->home->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Featured Visible', $response->body());
        self::assertStringContainsString('Home Visible', $response->body());
        self::assertStringContainsString('Featured', $response->body());
        self::assertStringContainsString('/featured-visible', $response->body());
        self::assertStringNotContainsString('Draft Secret', $response->body());
        self::assertStringNotContainsString('Private Secret', $response->body());
    }

    public function testHomeRendersEmptyStateWhenNoPublishedPlacementPages(): void
    {
        $this->pages->create(['title' => 'Only Draft', 'state' => 'draft', 'author_name' => 'Admin']);

        $response = $this->home->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('No published content', $response->body());
    }

    public function testConfiguredHomepageRendersSinglePublishedPage(): void
    {
        $page = $this->createPublished('The Homepage', 'none', '2026-01-01T00:00:00Z');
        $this->setHomepage($page['id']);

        $response = $this->home->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<h1>The Homepage</h1>', $response->body());
    }

    public function testConfiguredHomepageFallsBackToStreamWhenNotPublished(): void
    {
        $draft = $this->pages->create(['title' => 'Unpublished Homepage', 'state' => 'draft', 'author_name' => 'Admin']);
        $this->setHomepage($draft['id']);
        $this->createPublished('Stream Page', 'home', '2026-01-01T00:00:00Z');

        $response = $this->home->index();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Stream Page', $response->body());
        self::assertStringNotContainsString('Unpublished Homepage', $response->body());
    }

    /** @return array{id: string, title: string} */
    private function createPublished(string $title, string $placement, string $publishedAt): array
    {
        return $this->pages->create([
            'title' => $title,
            'state' => 'published',
            'placement' => $placement,
            'published_at' => $publishedAt,
            'author_name' => 'Admin',
        ]);
    }

    private function setHomepage(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, revision, updated_at) VALUES (:key, :value, 1, :updated)'
        );
        $statement->execute(['key' => 'homepage_id', 'value' => $id, 'updated' => gmdate('Y-m-d\TH:i:s\Z')]);
    }
}
