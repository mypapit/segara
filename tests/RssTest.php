<?php

declare(strict_types=1);

namespace Tests;

use DOMDocument;
use DOMElement;
use DOMNode;
use PDO;
use Segara\Config;
use Segara\Feeds\RssController;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class RssTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;
    private MediaRepository $media;
    private RssController $rss;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
        $this->media = new MediaRepository($this->pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'base_path' => '/segara', 'environment' => 'test']);
        $this->rss = new RssController($this->config, $this->pages, $this->media);
    }

    public function testFeedReturnsRss2WithPublishedItemsNewestFirst(): void
    {
        $this->page(['title' => 'Older', 'slug' => 'older', 'published_at' => '2026-10-01T00:00:00Z']);
        $this->page(['title' => 'Newer', 'slug' => 'newer', 'published_at' => '2026-10-20T00:00:00Z']);

        $response = $this->rss->feed();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/rss+xml', $response->headers()['Content-Type'] ?? '');

        $doc = $this->parse($response->body());
        self::assertSame('2.0', $this->element($doc, 'rss')->getAttribute('version'));
        self::assertSame(1, $doc->getElementsByTagName('channel')->length);
        $items = $doc->getElementsByTagName('item');
        self::assertSame(2, $items->length);
        self::assertSame('Newer', $this->itemText($items->item(0), 'title'));
        self::assertSame('Older', $this->itemText($items->item(1), 'title'));
    }

    public function testFeedExcludesDraftPrivateAndTrashPages(): void
    {
        $this->page(['title' => 'Visible', 'slug' => 'visible', 'published_at' => '2026-10-20T00:00:00Z']);
        $this->pages->create(['title' => 'Draft', 'slug' => 'draft-page', 'state' => 'draft']);
        $this->pages->create(['title' => 'Trash', 'slug' => 'trash-page', 'state' => 'trash']);
        $this->pages->create([
            'title' => 'Private', 'slug' => 'private-page', 'state' => 'private',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $doc = $this->parse($this->rss->feed()->body());
        $items = $doc->getElementsByTagName('item');
        self::assertSame(1, $items->length);
        self::assertSame('Visible', $this->itemText($items->item(0), 'title'));
    }

    public function testFeedEmitsEnclosureForFeaturedMedia(): void
    {
        $media = $this->media->create([
            'original_name' => 'cover.jpg', 'stored_path' => str_repeat('a', 32) . '.jpg',
            'mime' => 'image/jpeg', 'size' => 4096, 'alt_text' => 'Cover image', 'decorative' => 0,
        ]);
        $this->page(['featured_media_id' => $media['id']]);

        $doc = $this->parse($this->rss->feed()->body());
        self::assertSame(1, $doc->getElementsByTagName('enclosure')->length);
        $enclosure = $this->element($doc, 'enclosure');
        self::assertStringContainsString('/media.php?id=' . $media['id'], $enclosure->getAttribute('url'));
        self::assertSame('4096', $enclosure->getAttribute('length'));
        self::assertSame('image/jpeg', $enclosure->getAttribute('type'));
    }

    public function testFeedOmitsEnclosureWhenNoFeaturedMedia(): void
    {
        $this->page(['title' => 'Plain', 'slug' => 'plain']);
        $doc = $this->parse($this->rss->feed()->body());
        self::assertSame(0, $doc->getElementsByTagName('enclosure')->length);
    }

    public function testFeedEscapesSpecialCharactersAndParses(): void
    {
        $this->page(['title' => 'A & B < C > "D"', 'slug' => 'special']);
        $doc = $this->parse($this->rss->feed()->body());
        $items = $doc->getElementsByTagName('item');
        self::assertSame('A & B < C > "D"', $this->itemText($items->item(0), 'title'));
    }

    public function testGuidsAreStableCanonicalUrls(): void
    {
        $this->page(['title' => 'About Us', 'slug' => 'about-us']);
        $doc = $this->parse($this->rss->feed()->body());
        $guid = $this->itemText($doc->getElementsByTagName('item')->item(0), 'guid');
        self::assertSame('https://example.test/segara/about-us', $guid);
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

    private function parse(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        self::assertTrue(@$doc->loadXML($xml), 'Feed output is not well-formed XML.');

        return $doc;
    }

    private function element(DOMDocument $doc, string $tag): DOMElement
    {
        $node = $doc->getElementsByTagName($tag)->item(0);
        self::assertNotNull($node, 'Expected a <' . $tag . '> element.');

        return $node;
    }

    private function itemText(?DOMNode $item, string $tag): string
    {
        self::assertInstanceOf(DOMElement::class, $item);
        $node = $item->getElementsByTagName($tag)->item(0);

        return $node === null ? '' : (string) $node->textContent;
    }
}
