<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use Segara\Config;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\Transfer\WxrExport;
use Segara\Transfer\WxrImport;
use Tests\Support\TestCase;

final class TransferTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
    }

    public function testRoundTripPreservesContentStateTagsAndButtons(): void
    {
        $this->page([
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'body_markdown' => "## Heading\n\nSome **bold** text",
            'description' => 'A description',
            'state' => 'published',
            'published_at' => '2026-10-20T00:00:00Z',
            'tags' => ['news', 'tech'],
            'buttons' => [['icon' => 'link', 'label' => 'More', 'target_type' => 'custom', 'target' => '/about']],
            'video_path' => '/videos/intro.mp4',
        ]);
        $this->page(['title' => 'Draft Page', 'slug' => 'draft-page', 'state' => 'draft']);

        $xml = (new WxrExport($this->config, $this->pages))->export();
        $target = $this->targetRepository();
        $result = (new WxrImport($target))->import($xml);

        self::assertSame(2, $result['imported']);
        self::assertSame([], $result['conflicts']);
        self::assertSame([], $result['errors']);

        $imported = $target->getBySlug('hello-world');
        self::assertNotNull($imported);
        self::assertSame('Hello World', $imported['title']);
        self::assertSame('published', $imported['state']);
        self::assertSame('A description', $imported['description']);
        self::assertSame("## Heading\n\nSome **bold** text", $imported['body_markdown']);
        self::assertSame(['news', 'tech'], $imported['tags']);
        self::assertSame('/videos/intro.mp4', $imported['video_path']);
        self::assertSame('2026-10-20T00:00:00Z', $imported['published_at']);

        $draft = $target->getBySlug('draft-page');
        self::assertNotNull($draft);
        self::assertSame('draft', $draft['state']);
    }

    public function testUnicodeRoundTrip(): void
    {
        $this->page([
            'title' => 'Café Résumé',
            'slug' => 'cafe-resume',
            'body_markdown' => "Bold **héllo** and emoji 🌍 and 日本語",
            'state' => 'published',
        ]);

        $xml = (new WxrExport($this->config, $this->pages))->export();
        $target = $this->targetRepository();
        (new WxrImport($target))->import($xml);

        $imported = $target->getBySlug('cafe-resume');
        self::assertNotNull($imported);
        self::assertSame('Café Résumé', $imported['title']);
        self::assertSame("Bold **héllo** and emoji 🌍 and 日本語", $imported['body_markdown']);
    }

    public function testRejectsDoctypeDeclaration(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<rss version="2.0"><channel><item><title>&xxe;</title></item></channel></rss>';

        $this->expectException(InvalidArgumentException::class);
        (new WxrImport($this->pages))->import($xml);
    }

    public function testRejectsEntityDeclaration(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><item><title><!ENTITY foo "bar"></title></item></channel></rss>';

        $this->expectException(InvalidArgumentException::class);
        (new WxrImport($this->pages))->import($xml);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WxrImport($this->pages))->import('<rss><channel><item><title>unclosed');
    }

    public function testDuplicateSlugReportedAsConflict(): void
    {
        $this->page(['title' => 'X', 'slug' => 'dup-slug']);

        $xml = (new WxrExport($this->config, $this->pages))->export();
        $result = (new WxrImport($this->pages))->import($xml);

        self::assertSame(0, $result['imported']);
        self::assertSame(['dup-slug'], $result['conflicts']);
    }

    public function testExportOmitsPasswordHashesAndMarksPrivateAsDraft(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->pages->create([
            'title' => 'Private', 'slug' => 'private-page', 'state' => 'private',
            'password_hash' => $hash,
        ]);

        $xml = (new WxrExport($this->config, $this->pages))->export();
        self::assertStringNotContainsString($hash, $xml);
        self::assertStringNotContainsString('password', $xml);
        self::assertStringContainsString('<wp:status>draft</wp:status>', $xml);

        $target = $this->targetRepository();
        $result = (new WxrImport($target))->import($xml);
        self::assertSame(1, $result['imported']);
        $imported = $target->getBySlug('private-page');
        self::assertNotNull($imported);
        self::assertSame('draft', $imported['state']);
        self::assertNull($imported['password_hash']);
    }

    private function targetRepository(): PageRepository
    {
        $path = $this->storePath . '/transfer-target-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = Database::connect($path);
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();

        return new PageRepository($pdo);
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
