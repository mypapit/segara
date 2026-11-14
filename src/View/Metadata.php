<?php

declare(strict_types=1);

namespace Segara\View;

use DateTimeImmutable;
use InvalidArgumentException;
use Segara\Config;

final class Metadata
{
    private const SCHEMA_TYPES = ['WebPage', 'Article', 'ProfilePage', 'ContactPage', 'AboutPage'];
    private const AUTHOR_TYPES = ['Person', 'Organization'];

    /**
     * @param array{type: string, name: string}|null $author
     * @param list<array{name: string, path: string}> $breadcrumbs
     */
    private function __construct(
        private readonly Links $links,
        private readonly string $title,
        private readonly string $siteName,
        private readonly string $language,
        private readonly ?string $description,
        private readonly string $path,
        private readonly string $schemaType,
        private readonly ?string $publishedAt,
        private readonly ?string $modifiedAt,
        private readonly ?array $author,
        private readonly array $breadcrumbs,
        private readonly ?string $imagePath,
        private readonly ?string $robots,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(Config $config, array $values): self
    {
        $links = new Links($config);
        $schemaType = $values['schema_type'] ?? 'WebPage';
        if (!is_string($schemaType) || !in_array($schemaType, self::SCHEMA_TYPES, true)) {
            throw new InvalidArgumentException('Metadata schema type is not supported.');
        }

        $path = $values['path'] ?? '/';
        if (!is_string($path)) {
            throw new InvalidArgumentException('Metadata path must be a local URL path.');
        }
        $links->path($path);

        $description = self::optionalText($values, 'description', 1000);
        $publishedAt = self::optionalDate($values, 'published_at');
        $modifiedAt = self::optionalDate($values, 'modified_at');
        $imagePath = self::optionalText($values, 'image_path', 2048);
        if ($imagePath !== null) {
            $links->path($imagePath);
        }

        return new self(
            $links,
            self::requiredText($values, 'title', 300),
            self::text($values, 'site_name', 'Segara', 300),
            self::validateLanguage($values['language'] ?? 'en'),
            $description,
            $path,
            $schemaType,
            $publishedAt,
            $modifiedAt,
            self::author($values['author'] ?? null),
            self::breadcrumbs($values['breadcrumbs'] ?? []),
            $imagePath,
            self::robots($values['robots'] ?? null),
        );
    }

    public static function admin(Config $config): self
    {
        return new self(
            new Links($config),
            'Administration',
            'Segara',
            'en',
            null,
            '/admin.php',
            'WebPage',
            null,
            null,
            null,
            [],
            null,
            'noindex, nofollow',
        );
    }

    public function language(): string
    {
        return $this->language;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => $this->graph(),
        ];
    }

    /** @return array<string, mixed> */
    public function headData(): array
    {
        return [
            'title' => $this->title,
            'site_name' => $this->siteName,
            'language' => $this->language,
            'description' => $this->description,
            'canonical' => $this->links->url($this->path),
            'schema_type' => $this->schemaType,
            'opengraph_type' => $this->schemaType === 'Article' ? 'article' : 'website',
            'published_at' => $this->publishedAt,
            'modified_at' => $this->modifiedAt,
            'author' => $this->author,
            'image' => $this->imagePath === null ? null : $this->links->url($this->imagePath),
            'robots' => $this->robots,
            'json_ld' => $this->document(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function graph(): array
    {
        $siteUrl = $this->links->url();
        $canonical = $this->links->url($this->path);
        $graph = [[
            '@type' => 'WebSite',
            '@id' => $siteUrl . '#website',
            'url' => $siteUrl,
            'name' => $this->siteName,
            'inLanguage' => $this->language,
        ]];
        $page = [
            '@type' => $this->schemaType,
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $this->title,
            'isPartOf' => ['@id' => $siteUrl . '#website'],
            'inLanguage' => $this->language,
        ];
        if ($this->schemaType === 'Article') {
            $page['headline'] = $this->title;
        }
        if ($this->description !== null) {
            $page['description'] = $this->description;
        }
        if ($this->publishedAt !== null) {
            $page['datePublished'] = $this->publishedAt;
        }
        if ($this->modifiedAt !== null) {
            $page['dateModified'] = $this->modifiedAt;
        }
        if ($this->author !== null) {
            $page['author'] = ['@type' => $this->author['type'], 'name' => $this->author['name']];
        }
        if ($this->imagePath !== null) {
            $page['image'] = $this->links->url($this->imagePath);
        }
        $graph[] = $page;

        if ($this->breadcrumbs !== []) {
            $items = [];
            foreach ($this->breadcrumbs as $index => $breadcrumb) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $breadcrumb['name'],
                    'item' => $this->links->url($breadcrumb['path']),
                ];
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
            $page['breadcrumb'] = ['@id' => $canonical . '#breadcrumb'];
            $graph[1] = $page;
            $graph[2]['@id'] = $canonical . '#breadcrumb';
        }

        return $graph;
    }

    /** @param array<string, mixed> $values */
    private static function requiredText(array $values, string $key, int $maximum): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Metadata ' . $key . ' must be a non-empty string.');
        }

        return self::validateText($value, $key, $maximum);
    }

    /** @param array<string, mixed> $values */
    private static function text(array $values, string $key, string $default, int $maximum): string
    {
        $value = $values[$key] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Metadata ' . $key . ' must be a non-empty string.');
        }

        return self::validateText($value, $key, $maximum);
    }

    /** @param array<string, mixed> $values */
    private static function optionalText(array $values, string $key, int $maximum): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Metadata ' . $key . ' must be a string.');
        }

        return self::validateText($value, $key, $maximum);
    }

    private static function validateText(string $value, string $key, int $maximum): string
    {
        if (strlen($value) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Metadata ' . $key . ' contains invalid text.');
        }

        return $value;
    }

    private static function validateLanguage(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Metadata language is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function optionalDate(array $values, string $key): ?string
    {
        $value = self::optionalText($values, $key, 100);
        if ($value !== null) {
            try {
                new DateTimeImmutable($value);
            } catch (\Exception) {
                throw new InvalidArgumentException('Metadata ' . $key . ' must be a valid date/time.');
            }
        }

        return $value;
    }

    /** @return array{type: string, name: string}|null */
    private static function author(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !isset($value['type'], $value['name'])
            || !is_string($value['type']) || !in_array($value['type'], self::AUTHOR_TYPES, true)
            || !is_string($value['name']) || trim($value['name']) === ''
        ) {
            throw new InvalidArgumentException('Metadata author must be a named Person or Organization.');
        }

        return ['type' => $value['type'], 'name' => self::validateText($value['name'], 'author', 300)];
    }

    /** @return list<array{name: string, path: string}> */
    private static function breadcrumbs(mixed $value): array
    {
        if (!is_array($value) || count($value) > 20) {
            throw new InvalidArgumentException('Metadata breadcrumbs must be a bounded list.');
        }
        $breadcrumbs = [];
        foreach ($value as $breadcrumb) {
            if (!is_array($breadcrumb) || !isset($breadcrumb['name'], $breadcrumb['path'])
                || !is_string($breadcrumb['name']) || trim($breadcrumb['name']) === ''
                || !is_string($breadcrumb['path'])
            ) {
                throw new InvalidArgumentException('Metadata breadcrumb is invalid.');
            }
            $breadcrumbs[] = [
                'name' => self::validateText($breadcrumb['name'], 'breadcrumb', 300),
                'path' => $breadcrumb['path'],
            ];
        }

        return $breadcrumbs;
    }

    private static function robots(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !in_array($value, ['noindex, nofollow', 'noindex', 'nofollow'], true)) {
            throw new InvalidArgumentException('Metadata robots value is not supported.');
        }

        return $value;
    }
}
