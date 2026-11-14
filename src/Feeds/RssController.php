<?php

declare(strict_types=1);

namespace Segara\Feeds;

use Segara\Config;
use Segara\Content\Markdown;
use Segara\Http\Response;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;

final class RssController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly MediaRepository $media,
    ) {
    }

    public function feed(): Response
    {
        $xml = new Xml();
        $xml->stylesheet('rss-styles.xml');
        $xml->start('rss')->attribute('version', '2.0');
        $xml->start('channel');
        $xml->element('title', 'Segara');
        $xml->element('link', $this->url('/'));
        $xml->element('description', 'Latest published pages');

        foreach ($this->pages->listPublished(20) as $page) {
            $xml->start('item');
            $xml->element('title', $page['title']);
            $xml->element('link', $this->url('/' . $page['slug']));
            $xml->element('guid', $this->url('/' . $page['slug']));
            $pubDate = $this->pubDate($page['published_at']);
            if ($pubDate !== '') {
                $xml->element('pubDate', $pubDate);
            }
            $xml->element('description', (new Markdown())->render($page['body_markdown']));
            $this->enclosure($xml, $page['featured_media_id']);
            $xml->end();
        }

        $xml->end();
        $xml->end();

        return new Response(200, ['Content-Type' => 'application/rss+xml; charset=UTF-8'], $xml->toString());
    }

    private function url(string $path): string
    {
        return $this->config->baseUrl . $this->config->basePath . $path;
    }

    private function pubDate(?string $publishedAt): string
    {
        if ($publishedAt === null) {
            return '';
        }
        $timestamp = strtotime($publishedAt);
        if ($timestamp === false) {
            return '';
        }

        return gmdate(DATE_RSS, $timestamp);
    }

    private function enclosure(Xml $xml, ?string $featuredMediaId): void
    {
        if ($featuredMediaId === null) {
            return;
        }
        $media = $this->media->getById($featuredMediaId);
        if ($media === null) {
            return;
        }

        $xml->start('enclosure')
            ->attribute('url', $this->url('/media.php?id=' . $media['id']))
            ->attribute('length', (string) $media['size'])
            ->attribute('type', $media['mime'])
            ->end();
    }
}
