<?php

declare(strict_types=1);

namespace Tests;

use DOMDocument;
use PDO;
use Segara\Config;
use Segara\Maintenance\PublicFiles;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class PublicFilesTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;
    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
        $this->publicRoot = sys_get_temp_dir() . '/segara-public-' . bin2hex(random_bytes(8));
        mkdir($this->publicRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['sitemap.xml', 'robots.txt', 'llms.txt'] as $name) {
            $path = $this->publicRoot . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->publicRoot);
        parent::tearDown();
    }

    public function testRebuildWritesThreeFilesWithCanonicalOrigin(): void
    {
        $this->page(['title' => 'About', 'slug' => 'about', 'description' => 'About us']);

        $written = $this->service()->rebuild();
        self::assertSame(['sitemap.xml', 'robots.txt', 'llms.txt'], $written);
        foreach ($written as $name) {
            self::assertFileExists($this->publicRoot . '/' . $name);
        }

        $sitemap = $this->read('sitemap.xml');
        $doc = new DOMDocument();
        self::assertTrue(@$doc->loadXML($sitemap), 'Sitemap is not well-formed XML.');
        $urls = [];
        foreach ($doc->getElementsByTagName('loc') as $loc) {
            $urls[] = (string) $loc->textContent;
        }
        self::assertContains('https://example.test/', $urls);
        self::assertContains('https://example.test/about', $urls);

        $robots = $this->read('robots.txt');
        self::assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $robots);
        self::assertStringContainsString('Disallow: /admin', $robots);

        $llms = $this->read('llms.txt');
        self::assertStringContainsString('https://example.test/about', $llms);
        self::assertStringContainsString('About us', $llms);
    }

    public function testOnlyPublishedPagesAreListed(): void
    {
        $this->page(['title' => 'Live', 'slug' => 'live']);
        $this->pages->create(['title' => 'Draft', 'slug' => 'draft-page', 'state' => 'draft']);
        $this->pages->create(['title' => 'Trash', 'slug' => 'trash-page', 'state' => 'trash']);
        $this->pages->create([
            'title' => 'Private', 'slug' => 'private-page', 'state' => 'private',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);

        $this->service()->rebuild();
        $sitemap = $this->read('sitemap.xml');
        self::assertStringContainsString('/live', $sitemap);
        self::assertStringNotContainsString('draft-page', $sitemap);
        self::assertStringNotContainsString('trash-page', $sitemap);
        self::assertStringNotContainsString('private-page', $sitemap);
    }

    public function testReplacesExistingFilesWithoutLeavingTempFiles(): void
    {
        file_put_contents($this->publicRoot . '/sitemap.xml', 'stale');
        $this->page();

        $this->service()->rebuild();
        self::assertNotSame('stale', $this->read('sitemap.xml'));
        $temps = glob($this->publicRoot . '/*.tmp-*');
        self::assertSame([], $temps, 'Atomic write left temporary files behind.');
    }

    public function testSubdirectoryPrefixIsApplied(): void
    {
        $this->page(['title' => 'About', 'slug' => 'about']);

        $this->service('https://example.test', '/segara')->rebuild();
        self::assertStringContainsString('https://example.test/segara/about', $this->read('sitemap.xml'));
        self::assertStringContainsString('Disallow: /segara/admin', $this->read('robots.txt'));
    }

    public function testLlmsEscapesBracketsInTitles(): void
    {
        $this->page(['title' => 'A [B] C', 'slug' => 'brackets']);

        $this->service()->rebuild();
        self::assertStringContainsString('A \[B\] C', $this->read('llms.txt'));
    }

    private function service(string $baseUrl = 'https://example.test', string $basePath = ''): PublicFiles
    {
        return new PublicFiles(
            Config::fromArray(['base_url' => $baseUrl, 'base_path' => $basePath, 'environment' => 'test']),
            $this->pages,
            $this->publicRoot,
        );
    }

    private function read(string $name): string
    {
        $content = file_get_contents($this->publicRoot . '/' . $name);
        self::assertNotFalse($content, "Expected {$name} to be written.");

        return $content;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return $this->pages->create($overrides + [
            'title' => 'Sample Page',
            'slug' => 'sample-page',
            'state' => 'published',
            'published_at' => '2026-10-25T00:00:00Z',
        ]);
    }
}
