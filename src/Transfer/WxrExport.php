<?php

declare(strict_types=1);

namespace Segara\Transfer;

use Segara\Config;
use Segara\Feeds\Xml;
use Segara\Pages\PageRepository;
use Segara\Support\Slug;

/**
 * Serialise pages, tags, and authors into WordPress eXtended RSS 1.2.
 * Never emits admin records, session/throttle data, contact messages, or
 * password hashes: private pages are exported with status "draft".
 *
 * @phpstan-import-type Page from PageRepository
 */
final class WxrExport
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
    ) {
    }

    public function export(): string
    {
        $pages = [];
        foreach ($this->pages->listAll() as $page) {
            if ($page['state'] !== 'trash') {
                $pages[] = $page;
            }
        }

        $xml = new Xml();
        $xml->start('rss')
            ->attribute('version', '2.0')
            ->attribute('xmlns:excerpt', 'http://wordpress.org/export/1.2/excerpt/')
            ->attribute('xmlns:content', 'http://wordpress.org/export/1.2/content/')
            ->attribute('xmlns:wfw', 'http://wellformedweb.org/CommentAPI/')
            ->attribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/')
            ->attribute('xmlns:wp', 'http://wordpress.org/export/1.2/');
        $xml->start('channel');
        $xml->element('title', 'Segara');
        $xml->element('link', $this->url('/'));
        $xml->element('description', 'Segara content export');
        $xml->element('pubDate', gmdate(DATE_RSS));
        $xml->element('language', 'en');
        $xml->element('wxr_version', '1.2');
        $xml->element('wp:base_site_url', $this->url('/'));
        $xml->element('wp:base_blog_url', $this->url('/'));

        foreach ($this->authors($pages) as $author) {
            $xml->start('wp:author');
            $xml->element('wp:author_login', $this->login($author));
            $xml->element('wp:author_email', '');
            $xml->element('wp:author_display_name', $author);
            $xml->element('wp:author_first_name', '');
            $xml->element('wp:author_last_name', '');
            $xml->end();
        }

        foreach ($this->tags($pages) as $name) {
            $xml->start('wp:tag');
            $xml->element('wp:tag_slug', Slug::fromTitle($name));
            $xml->element('wp:tag_name', $name);
            $xml->end();
        }

        foreach ($pages as $page) {
            $this->item($xml, $page);
        }

        $xml->end();
        $xml->end();

        return $xml->toString();
    }

    /** @param Page $page */
    private function item(Xml $xml, array $page): void
    {
        $status = $page['state'] === 'published' ? 'publish' : 'draft';
        $xml->start('item');
        $xml->element('title', $page['title']);
        $xml->element('link', $this->url('/' . $page['slug']));
        $xml->element('pubDate', $this->rssDate($page['published_at'] ?? $page['created_at']));
        $xml->element('dc:creator', $page['author_name']);
        $xml->start('guid')->attribute('isPermaLink', 'false')->text('segara-' . $page['id'])->end();
        $xml->element('description', '');
        $xml->start('content:encoded')->cdata($page['body_markdown'])->end();
        $xml->element('wp:post_id', $page['id']);
        $xml->element('wp:post_date', $this->w3cDate($page['created_at']));
        $xml->element('wp:post_date_gmt', $this->w3cDate($page['created_at']));
        $xml->element('wp:post_name', $page['slug']);
        $xml->element('wp:status', $status);
        $xml->element('wp:post_type', 'page');

        $this->meta($xml, 'segara_description', $page['description']);
        $this->meta($xml, 'segara_schema_type', $page['schema_type']);
        $this->meta($xml, 'segara_placement', $page['placement']);
        $this->meta($xml, 'segara_show_contact_form', (string) $page['show_contact_form']);
        $this->meta($xml, 'segara_buttons_json', $page['buttons_json']);
        if ($page['video_path'] !== null) {
            $this->meta($xml, 'segara_video_path', $page['video_path']);
        }
        if ($page['featured_media_id'] !== null) {
            $this->meta($xml, 'segara_featured_media_id', $page['featured_media_id']);
        }
        if ($page['published_at'] !== null) {
            $this->meta($xml, 'segara_published_at', $page['published_at']);
        }

        foreach ($page['tags'] as $tag) {
            $xml->start('category')
                ->attribute('domain', 'post_tag')
                ->attribute('nicename', Slug::fromTitle($tag))
                ->text($tag)
                ->end();
        }
        $xml->end();
    }

    private function meta(Xml $xml, string $key, string $value): void
    {
        $xml->start('wp:postmeta');
        $xml->element('wp:meta_key', $key);
        $xml->start('wp:meta_value')->cdata($value)->end();
        $xml->end();
    }

    /**
     * @param list<Page> $pages
     * @return list<string>
     */
    private function authors(array $pages): array
    {
        $authors = [];
        foreach ($pages as $page) {
            if ($page['author_name'] !== '' && !in_array($page['author_name'], $authors, true)) {
                $authors[] = $page['author_name'];
            }
        }

        return $authors;
    }

    /**
     * @param list<Page> $pages
     * @return list<string>
     */
    private function tags(array $pages): array
    {
        $names = [];
        foreach ($pages as $page) {
            foreach ($page['tags'] as $tag) {
                if (!in_array($tag, $names, true)) {
                    $names[] = $tag;
                }
            }
        }

        return $names;
    }

    private function login(string $author): string
    {
        $slug = Slug::fromTitle($author);

        return $slug === '' ? 'author' : $slug;
    }

    private function url(string $path): string
    {
        return $this->config->baseUrl . $this->config->basePath . $path;
    }

    private function rssDate(string $iso): string
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? '' : gmdate(DATE_RSS, $timestamp);
    }

    private function w3cDate(string $iso): string
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp);
    }
}
