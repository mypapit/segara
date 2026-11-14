<?php

declare(strict_types=1);

namespace Segara\Pages;

use DateTimeImmutable;
use InvalidArgumentException;
use Segara\Support\Slug;

/**
 * @phpstan-type ValidatedPage array{title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, placement: string, author_id: ?string, author_name: string, published_at: ?string, password_hash: ?string, tags: list<string>, buttons_json: string, video_path: ?string, show_contact_form: int, featured_media_id: ?string}
 * @psalm-type ValidatedPage = array{title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, placement: string, author_id: ?string, author_name: string, published_at: ?string, password_hash: ?string, tags: list<string>, buttons_json: string, video_path: ?string, show_contact_form: int, featured_media_id: ?string}
 */
final class PageValidator
{
    private const STATES = ['published', 'private', 'draft', 'trash'];
    private const SCHEMA_TYPES = ['WebPage', 'Article', 'ProfilePage', 'ContactPage', 'AboutPage'];
    private const PLACEMENTS = ['none', 'home', 'featured'];

    /**
     * @param array<string, mixed> $data
     * @return ValidatedPage
     */
    public function validate(array $data): array
    {
        $title = $this->stringValue($data, 'title', '');
        $this->assertUtf8($title, 'Title');
        if (trim($title) === '') {
            throw new InvalidArgumentException('Title is required.');
        }
        if (mb_strlen($title) > 200) {
            throw new InvalidArgumentException('Title must not exceed 200 characters.');
        }

        $slugSource = $this->stringValue($data, 'slug', '');
        $this->assertUtf8($slugSource, 'Slug');
        if (trim($slugSource) !== '' && Slug::isReserved(strtolower(trim($slugSource)))) {
            throw new InvalidArgumentException('Slug is reserved for a system route.');
        }
        $slug = Slug::fromTitle(trim($slugSource) === '' ? $title : $slugSource);
        if (Slug::isReserved($slug)) {
            throw new InvalidArgumentException('Slug is reserved for a system route.');
        }

        $body = $this->stringValue($data, 'body_markdown', '');
        $this->assertUtf8($body, 'Body');
        if (strlen($body) > 1048576) {
            throw new InvalidArgumentException('Body must not exceed 1 MiB.');
        }

        $description = $this->stringValue($data, 'description', '');
        $this->assertUtf8($description, 'Description');
        if (mb_strlen($description) > 500) {
            throw new InvalidArgumentException('Description must not exceed 500 characters.');
        }

        $state = $this->stringValue($data, 'state', 'draft');
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('State must be published, private, draft, or trash.');
        }
        $schemaType = $this->stringValue($data, 'schema_type', 'WebPage');
        if (!in_array($schemaType, self::SCHEMA_TYPES, true)) {
            throw new InvalidArgumentException('Schema type is not supported.');
        }
        $placement = $this->stringValue($data, 'placement', 'none');
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new InvalidArgumentException('Placement must be none, home, or featured.');
        }

        $authorId = $this->value($data, 'author_id', null);
        if ($authorId !== null && (!is_string($authorId) || preg_match('/^[a-f0-9]{32}$/', $authorId) !== 1)) {
            throw new InvalidArgumentException('Author ID must be a 32-character lowercase hex ID or null.');
        }
        $authorName = $this->stringValue($data, 'author_name', '');
        $this->assertUtf8($authorName, 'Author name');
        if (mb_strlen($authorName) > 200) {
            throw new InvalidArgumentException('Author name must not exceed 200 characters.');
        }

        $passwordHash = $this->value($data, 'password_hash', null);
        if ($passwordHash !== null) {
            if (!is_string($passwordHash) || password_get_info($passwordHash)['algoName'] === 'unknown') {
                throw new InvalidArgumentException('Password protection requires a valid password hash.');
            }
        }
        if ($state === 'private' && $passwordHash === null) {
            throw new InvalidArgumentException('A private page requires a password hash.');
        }

        $publishedAt = $this->value($data, 'published_at', null);
        if ($publishedAt !== null) {
            if (!is_string($publishedAt) || !$this->isUtcTimestamp($publishedAt)) {
                throw new InvalidArgumentException('Publication time must be a UTC ISO-8601 timestamp.');
            }
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'body_markdown' => $body,
            'description' => $description,
            'state' => $state,
            'schema_type' => $schemaType,
            'placement' => $placement,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'published_at' => $publishedAt,
            'password_hash' => $passwordHash,
            'tags' => $this->validateTags($this->value($data, 'tags', [])),
            'buttons_json' => $this->validateButtons($this->value($data, 'buttons', [])),
            'video_path' => LocalResource::videoPath($this->value($data, 'video_path', null)),
            'show_contact_form' => $this->showContactForm($this->value($data, 'show_contact_form', 0)),
            'featured_media_id' => $this->nullableId($this->value($data, 'featured_media_id', null)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key, string $default): string
    {
        $value = $this->value($data, $key, $default);
        if (!is_string($value)) {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function value(array $data, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    private function assertUtf8(string $value, string $field): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($field . ' must be valid UTF-8.');
        }
    }

    private function isUtcTimestamp(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /** @return list<string> */
    private function validateTags(mixed $tags): array
    {
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > 20) {
            throw new InvalidArgumentException('Tags must be a list containing at most 20 tags.');
        }

        $validated = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '' || !mb_check_encoding($tag, 'UTF-8') || mb_strlen($tag) > 100) {
                throw new InvalidArgumentException('Each tag must be a non-empty UTF-8 string of at most 100 characters.');
            }
            if (isset($seen[$tag])) {
                throw new InvalidArgumentException('Tags must not contain duplicates.');
            }
            $seen[$tag] = true;
            $validated[] = $tag;
        }

        return $validated;
    }

    private function validateButtons(mixed $buttons): string
    {
        $validated = (new ButtonValidator())->validate($buttons);

        return json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function showContactForm(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 1 || $value === '1') {
            return 1;
        }
        if ($value === 0 || $value === '0' || $value === null || $value === '') {
            return 0;
        }

        throw new InvalidArgumentException('Show contact form must be 0 or 1.');
    }

    private function nullableId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new InvalidArgumentException('Featured media must be a 32-character lowercase hex ID or null.');
        }

        return $value;
    }
}
