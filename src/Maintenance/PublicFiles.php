<?php

declare(strict_types=1);

namespace Segara\Maintenance;

use RuntimeException;
use Segara\Config;
use Segara\Feeds\Xml;
use Segara\Pages\PageRepository;

/**
 * Regenerate the public metadata files — sitemap.xml, robots.txt, and
 * llms.txt — from the currently published pages only. Every write is an
 * atomic replace (temp file + rename) so a crash never leaves a half-written
 * file behind.
 */
final class PublicFiles
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly string $publicRoot,
    ) {
    }

    /** @return list<string> */
    public function rebuild(): array
    {
        $entries = $this->publishedEntries();
        $this->atomicWrite('sitemap.xml', $this->sitemap($entries));
        $this->atomicWrite('robots.txt', $this->robots());
        $this->atomicWrite('llms.txt', $this->llms($entries));

        return ['sitemap.xml', 'robots.txt', 'llms.txt'];
    }

    /**
     * @return list<array{path: string, url: string, lastmod: ?string, title: string, description: string}>
     */
    private function publishedEntries(): array
    {
        $entries = [];
        foreach ($this->pages->listPublished(10000) as $page) {
            $entries[] = [
                'path' => '/' . $page['slug'],
                'url' => $this->url('/' . $page['slug']),
                'lastmod' => $page['updated_at'],
                'title' => $page['title'],
                'description' => $page['description'],
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{path: string, url: string, lastmod: ?string, title: string, description: string}> $entries
     */
    private function sitemap(array $entries): string
    {
        $xml = new Xml();
        $xml->stylesheet('sitemap-styles.xml');
        $xml->start('urlset')->attribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->start('url');
        $xml->element('loc', $this->url('/'));
        $xml->end();
        foreach ($entries as $entry) {
            $xml->start('url');
            $xml->element('loc', $entry['url']);
            if ($entry['lastmod'] !== null) {
                $xml->element('lastmod', $this->w3cDate($entry['lastmod']));
            }
            $xml->end();
        }
        $xml->end();

        return $xml->toString();
    }

    private function robots(): string
    {
        $base = rtrim($this->config->basePath, '/');
        $lines = [
            'User-agent: *',
            'Disallow: ' . $base . '/admin',
            'Disallow: ' . $base . '/admin.php',
            '',
            'Sitemap: ' . $this->url('/sitemap.xml'),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{path: string, url: string, lastmod: ?string, title: string, description: string}> $entries
     */
    private function llms(array $entries): string
    {
        $lines = [
            '# Segara',
            '',
            '> Latest published pages.',
            '',
            '- [' . $this->linkText('Home') . '](' . $this->url('/') . ')',
        ];
        foreach ($entries as $entry) {
            $description = trim($entry['description']);
            $suffix = $description === '' ? '' : ': ' . $description;
            $lines[] = '- [' . $this->linkText($entry['title']) . '](' . $entry['url'] . ')' . $suffix;
        }

        return implode("\n", $lines) . "\n";
    }

    private function url(string $path): string
    {
        return $this->config->baseUrl . $this->config->basePath . $path;
    }

    private function linkText(string $title): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $title);
    }

    private function w3cDate(string $iso): string
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function atomicWrite(string $name, string $content): void
    {
        $path = rtrim($this->publicRoot, '/') . '/' . $name;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Could not write {$name}.");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not atomically replace {$name}.");
        }
    }
}
