<?php

declare(strict_types=1);

namespace Segara\Transfer;

use InvalidArgumentException;
use Segara\Pages\PageRepository;
use Segara\Support\Slug;
use XMLReader;

/**
 * Import a WXR 1.2 document into the page store.
 *
 * Parsing uses XMLReader without LIBXML_NOENT or LIBXML_DTDLOAD, and DOCTYPE /
 * entity declarations are rejected up front, so neither internal nor external
 * entities can be expanded. Imported "private" pages become drafts, existing
 * IDs are never reused, and existing slugs are reported as conflicts rather
 * than overwritten.
 *
 * @phpstan-type ImportItem array{title: string, slug: string, body: string, status: string, creator: string, post_type: string, description: string, schema_type: string, placement: string, show_contact_form: int, buttons_json: string, video_path: ?string, featured_media_id: ?string, published_at: ?string, tags: list<string>}
 * @psalm-type ImportItem = array{title: string, slug: string, body: string, status: string, creator: string, post_type: string, description: string, schema_type: string, placement: string, show_contact_form: int, buttons_json: string, video_path: ?string, featured_media_id: ?string, published_at: ?string, tags: list<string>}
 */
final class WxrImport
{
    public function __construct(private readonly PageRepository $pages)
    {
    }

    /**
     * @return array{imported: int, conflicts: list<string>, errors: list<string>}
     */
    public function import(string $xml, int $maxBytes = 10485760): array
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('Import file is empty.');
        }
        if (strlen($xml) > $maxBytes) {
            throw new InvalidArgumentException('Import exceeds the maximum allowed size.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('DTD and entity declarations are not allowed.');
        }

        $items = $this->parseItems($xml);
        $imported = 0;
        $conflicts = [];
        $errors = [];

        foreach ($items as $item) {
            if ($item['post_type'] !== '' && $item['post_type'] !== 'page') {
                continue;
            }
            if ($item['title'] === '') {
                $errors[] = 'Item missing a title; skipped.';
                continue;
            }
            $slug = $item['slug'] !== '' ? $item['slug'] : Slug::fromTitle($item['title']);
            if ($this->pages->getBySlug($slug) !== null) {
                $conflicts[] = $slug;
                continue;
            }
            try {
                $this->pages->create([
                    'title' => $item['title'],
                    'slug' => $slug,
                    'body_markdown' => $item['body'],
                    'description' => $item['description'],
                    'state' => $item['status'] === 'publish' ? 'published' : 'draft',
                    'schema_type' => $item['schema_type'],
                    'placement' => $item['placement'],
                    'author_name' => $item['creator'],
                    'published_at' => $item['published_at'],
                    'buttons' => $this->buttons($item['buttons_json']),
                    'video_path' => $item['video_path'],
                    'show_contact_form' => $item['show_contact_form'],
                    'featured_media_id' => $item['featured_media_id'],
                    'tags' => $item['tags'],
                ]);
                $imported++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $slug . ': ' . $exception->getMessage();
            }
        }

        return ['imported' => $imported, 'conflicts' => $conflicts, 'errors' => $errors];
    }

    /** @return array<mixed> */
    private function buttons(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<ImportItem>
     */
    private function parseItems(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        try {
            if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET)) {
                throw new InvalidArgumentException('Import is not valid XML.');
            }
            $items = $this->readItems($reader);
            $reader->close();
            if (libxml_get_errors() !== []) {
                throw new InvalidArgumentException('Import is not valid XML.');
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<ImportItem> */
    private function readItems(XMLReader $reader): array
    {
        $items = [];
        $item = null;
        $leaf = null;
        $buffer = '';
        $pendingMetaKey = null;
        $categoryDomain = null;

        while ($reader->read()) {
            $type = $reader->nodeType;

            if ($type === XMLReader::ELEMENT) {
                $name = $reader->localName;
                if ($name === 'item') {
                    $item = $this->newItem();
                    $leaf = null;
                    $buffer = '';
                    $pendingMetaKey = null;
                    $categoryDomain = null;
                    continue;
                }
                if ($item !== null) {
                    $leaf = $name;
                    $buffer = '';
                    if ($name === 'category') {
                        $categoryDomain = $reader->getAttribute('domain');
                    }
                }
                continue;
            }

            if (($type === XMLReader::TEXT || $type === XMLReader::CDATA) && $item !== null && $leaf !== null) {
                $buffer .= $reader->value;
                continue;
            }

            if ($type === XMLReader::END_ELEMENT) {
                $name = $reader->localName;
                if ($name === 'item' && $item !== null) {
                    $items[] = $item;
                    $item = null;
                } elseif ($item !== null && $leaf !== null) {
                    $value = trim($buffer);
                    switch ($leaf) {
                        case 'title':
                            $item['title'] = $value;
                            break;
                        case 'post_name':
                            $item['slug'] = $value;
                            break;
                        case 'encoded':
                            $item['body'] = $buffer;
                            break;
                        case 'status':
                            $item['status'] = $value;
                            break;
                        case 'post_type':
                            $item['post_type'] = $value;
                            break;
                        case 'creator':
                            $item['creator'] = $value;
                            break;
                        case 'meta_key':
                            $pendingMetaKey = $value;
                            break;
                        case 'meta_value':
                            $this->applyMeta($item, $pendingMetaKey, $buffer);
                            break;
                        case 'category':
                            if ($categoryDomain === 'post_tag' && $value !== '') {
                                $item['tags'][] = $value;
                            }
                            break;
                    }
                }
                $leaf = null;
                $buffer = '';
                continue;
            }
        }

        $reader->close();

        return $items;
    }

    /** @return ImportItem */
    private function newItem(): array
    {
        return [
            'title' => '',
            'slug' => '',
            'body' => '',
            'status' => 'draft',
            'creator' => '',
            'post_type' => 'page',
            'description' => '',
            'schema_type' => 'WebPage',
            'placement' => 'none',
            'show_contact_form' => 0,
            'buttons_json' => '[]',
            'video_path' => null,
            'featured_media_id' => null,
            'published_at' => null,
            'tags' => [],
        ];
    }

    /** @param ImportItem $item */
    private function applyMeta(array &$item, ?string $key, string $value): void
    {
        switch ($key) {
            case 'segara_description':
                $item['description'] = $value;
                break;
            case 'segara_schema_type':
                $item['schema_type'] = $value;
                break;
            case 'segara_placement':
                $item['placement'] = $value;
                break;
            case 'segara_show_contact_form':
                $item['show_contact_form'] = $value === '1' ? 1 : 0;
                break;
            case 'segara_buttons_json':
                $item['buttons_json'] = $value;
                break;
            case 'segara_video_path':
                $item['video_path'] = $value === '' ? null : $value;
                break;
            case 'segara_featured_media_id':
                $item['featured_media_id'] = $value === '' ? null : $value;
                break;
            case 'segara_published_at':
                $item['published_at'] = $value === '' ? null : $value;
                break;
        }
    }
}
