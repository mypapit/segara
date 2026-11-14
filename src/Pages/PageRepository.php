<?php

declare(strict_types=1);

namespace Segara\Pages;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Segara\Storage\WriteConflict;
use Throwable;

/**
 * @phpstan-type Page array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, show_contact_form: int, featured_media_id: ?string, tags: list<string>}
 * @psalm-type Page = array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, show_contact_form: int, featured_media_id: ?string, tags: list<string>}
 * @phpstan-type AdminPage array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}
 * @psalm-type AdminPage = array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}
 */
final class PageRepository
{
    private const PAGE_COLUMNS = 'id, title, slug, body_markdown, description, state, schema_type, '
        . 'author_id, author_name, published_at, created_at, updated_at, revision, placement, password_hash, '
        . 'buttons_json, video_path, show_contact_form, featured_media_id';

    private readonly PageValidator $validator;

    public function __construct(private readonly PDO $pdo)
    {
        $this->validator = new PageValidator();
    }

    /**
     * @param array<string, mixed> $input
     * @return Page
     */
    public function create(array $input): array
    {
        $data = $this->validator->validate($input);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($data['published_at'] === null && in_array($data['state'], ['published', 'private'], true)) {
            $data['published_at'] = $now;
        }
        $id = bin2hex(random_bytes(16));

        $this->pdo->beginTransaction();
        try {
            $this->assertSlugAvailable($data['slug'], '');
            $statement = $this->pdo->prepare(
                'INSERT INTO pages '
                .                '(id, title, slug, body_markdown, description, state, schema_type, author_id, author_name, '
                . 'published_at, created_at, updated_at, revision, placement, password_hash, buttons_json, video_path, show_contact_form, featured_media_id) '
                . 'VALUES (:id, :title, :slug, :body_markdown, :description, :state, :schema_type, :author_id, '
                . ':author_name, :published_at, :created_at, :updated_at, 1, :placement, :password_hash, :buttons_json, :video_path, :show_contact_form, :featured_media_id)'
            );
            $statement->execute([
                'id' => $id,
                'title' => $data['title'],
                'slug' => $data['slug'],
                'body_markdown' => $data['body_markdown'],
                'description' => $data['description'],
                'state' => $data['state'],
                'schema_type' => $data['schema_type'],
                'author_id' => $data['author_id'],
                'author_name' => $data['author_name'],
                'published_at' => $data['published_at'],
                'created_at' => $now,
                'updated_at' => $now,
                'placement' => $data['placement'],
                'password_hash' => $data['password_hash'],
                'buttons_json' => $data['buttons_json'],
                'video_path' => $data['video_path'],
                'show_contact_form' => $data['show_contact_form'],
                'featured_media_id' => $data['featured_media_id'],
            ]);
            $this->syncTags($id, $data['tags']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->throwSlugCollision($exception);
            throw $exception;
        }

        $page = $this->getById($id);
        if ($page === null) {
            throw new RuntimeException('Created page could not be read back.');
        }

        return $page;
    }

    /** @return Page|null */
    public function getById(string $id): ?array
    {
        return $this->fetchPage('id', $id);
    }

    /** @return Page|null */
    public function getBySlug(string $slug): ?array
    {
        return $this->fetchPage('slug', $slug);
    }

    /**
     * @param array<string, mixed> $changes
     * @return Page
     */
    public function update(string $id, int $expectedRevision, array $changes): array
    {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be a positive integer.');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->getById($id);
            if ($existing === null) {
                throw WriteConflict::missing();
            }

            $merged = array_merge($existing, $changes);
            if (!array_key_exists('buttons', $changes)) {
                $decoded = json_decode($existing['buttons_json'], true);
                $merged['buttons'] = is_array($decoded) ? $decoded : [];
            }
            if (!array_key_exists('video_path', $changes)) {
                $merged['video_path'] = $existing['video_path'];
            }
            $data = $this->validator->validate($merged);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ($data['published_at'] === null && in_array($data['state'], ['published', 'private'], true)) {
                $data['published_at'] = $now;
            }
            if ($existing['state'] !== $data['state'] && $data['state'] !== 'published') {
                $this->assertNotHomepage($id, 'unpublished');
            }
            $this->assertSlugAvailable($data['slug'], $id);

            $statement = $this->pdo->prepare(
                'UPDATE pages SET title = :title, slug = :slug, body_markdown = :body_markdown, '
                . 'description = :description, state = :state, schema_type = :schema_type, author_id = :author_id, '
                . 'author_name = :author_name, published_at = :published_at, updated_at = :updated_at, '
                . 'revision = revision + 1, placement = :placement, password_hash = :password_hash, '
                . 'buttons_json = :buttons_json, video_path = :video_path, show_contact_form = :show_contact_form, featured_media_id = :featured_media_id '
                . 'WHERE id = :id AND revision = :expected_revision'
            );
            $statement->bindValue(':title', $data['title']);
            $statement->bindValue(':slug', $data['slug']);
            $statement->bindValue(':body_markdown', $data['body_markdown']);
            $statement->bindValue(':description', $data['description']);
            $statement->bindValue(':state', $data['state']);
            $statement->bindValue(':schema_type', $data['schema_type']);
            $statement->bindValue(':author_id', $data['author_id']);
            $statement->bindValue(':author_name', $data['author_name']);
            $statement->bindValue(':published_at', $data['published_at']);
            $statement->bindValue(':updated_at', $now);
            $statement->bindValue(':placement', $data['placement']);
            $statement->bindValue(':password_hash', $data['password_hash']);
            $statement->bindValue(':buttons_json', $data['buttons_json']);
            $statement->bindValue(':video_path', $data['video_path']);
            $statement->bindValue(':show_contact_form', $data['show_contact_form'], PDO::PARAM_INT);
            $statement->bindValue(':featured_media_id', $data['featured_media_id']);
            $statement->bindValue(':id', $id);
            $statement->bindValue(':expected_revision', $expectedRevision, PDO::PARAM_INT);
            $statement->execute();
            if ($statement->rowCount() !== 1) {
                throw $this->conflictFor($id);
            }

            $this->syncTags($id, $data['tags']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->throwSlugCollision($exception);
            throw $exception;
        }

        $page = $this->getById($id);
        if ($page === null) {
            throw new RuntimeException('Updated page could not be read back.');
        }

        return $page;
    }

    /** @return Page */
    public function transition(string $id, int $expectedRevision, string $state, ?string $passwordHash = null): array
    {
        $changes = ['state' => $state];
        if ($passwordHash !== null) {
            $changes['password_hash'] = $passwordHash;
        }

        return $this->update($id, $expectedRevision, $changes);
    }

    public function delete(string $id, int $expectedRevision): void
    {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be a positive integer.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertNotHomepage($id, 'deleted');
            $statement = $this->pdo->prepare('DELETE FROM pages WHERE id = :id AND revision = :expected_revision');
            $statement->bindValue(':id', $id);
            $statement->bindValue(':expected_revision', $expectedRevision, PDO::PARAM_INT);
            $statement->execute();
            if ($statement->rowCount() !== 1) {
                throw $this->conflictFor($id);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Return the published stream used by the homepage, with featured pages first.
     *
     * @return list<Page>
     */
    public function listHomepage(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PAGE_COLUMNS . ' FROM pages '
            . "WHERE state = 'published' AND placement IN ('home', 'featured') "
            . "ORDER BY CASE placement WHEN 'featured' THEN 0 ELSE 1 END, published_at DESC, id ASC LIMIT 20"
        );
        $statement->execute();
        $rows = $statement->fetchAll();
        $pages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Page row has an invalid storage type.');
            }
            $pages[] = $this->hydrate($row);
        }

        return $pages;
    }

    /** @return list<array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}> */
    public function listAdmin(?string $state = null): array
    {
        $sql = 'SELECT id, title, slug, state, updated_at, revision FROM pages';
        if ($state !== null) {
            $sql .= ' WHERE state = :state';
        }
        $sql .= ' ORDER BY updated_at DESC, id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($state === null ? [] : ['state' => $state]);
        $rows = $statement->fetchAll();
        $pages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Page row has an invalid storage type.');
            }
            $revision = $row['revision'] ?? null;
            if (!is_int($revision) && !is_string($revision)) {
                throw new RuntimeException('Page revision has an invalid storage type.');
            }
            $pages[] = [
                'id' => $this->rowString($row, 'id'),
                'title' => $this->rowString($row, 'title'),
                'slug' => $this->rowString($row, 'slug'),
                'state' => $this->rowString($row, 'state'),
                'updated_at' => $this->rowString($row, 'updated_at'),
                'revision' => (int) $revision,
            ];
        }

        return $pages;
    }

    /** @return array<string, int> state => count, for the list filter bar */
    public function stateCounts(): array
    {
        $statement = $this->pdo->query('SELECT state, COUNT(*) AS n FROM pages GROUP BY state');
        $counts = [];
        if ($statement === false) {
            throw new RuntimeException('Unable to count pages by state.');
        }
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Page count row has an invalid storage type.');
            }
            $state = $this->rowString($row, 'state');
            $count = $row['n'] ?? null;
            if (!is_int($count) && !is_string($count)) {
                throw new RuntimeException('Page count has an invalid storage type.');
            }
            $counts[$state] = (int) $count;
        }

        return $counts;
    }

    /**
     * Return the newest published pages for public listings and feeds.
     *
     * @return list<Page>
     */
    public function listPublished(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Published page limit must be between 1 and 10000.');
        }
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PAGE_COLUMNS . " FROM pages WHERE state = 'published' "
            . 'ORDER BY published_at DESC, id ASC LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $pages = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Page row has an invalid storage type.');
            }
            $pages[] = $this->hydrate($row);
        }

        return $pages;
    }

    /** @return list<Page> */
    public function listAll(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PAGE_COLUMNS . ' FROM pages ORDER BY created_at ASC, id ASC'
        );
        $statement->execute();
        $pages = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Page row has an invalid storage type.');
            }
            $pages[] = $this->hydrate($row);
        }

        return $pages;
    }

    /** @return Page|null */
    private function fetchPage(string $field, string $value): ?array
    {
        $sql = match ($field) {
            'id' => 'SELECT ' . self::PAGE_COLUMNS . ' FROM pages WHERE id = :value',
            'slug' => 'SELECT ' . self::PAGE_COLUMNS . ' FROM pages WHERE slug = :value',
            default => throw new InvalidArgumentException('Unsupported page lookup.'),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Page row has an invalid storage type.');
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return Page
     */
    private function hydrate(array $row): array
    {
        $id = $this->rowString($row, 'id');
        $revision = $row['revision'] ?? null;
        if (!is_int($revision) && !is_string($revision)) {
            throw new RuntimeException('Page revision has an invalid storage type.');
        }

        return [
            'id' => $id,
            'title' => $this->rowString($row, 'title'),
            'slug' => $this->rowString($row, 'slug'),
            'body_markdown' => $this->rowString($row, 'body_markdown'),
            'description' => $this->rowString($row, 'description'),
            'state' => $this->rowString($row, 'state'),
            'schema_type' => $this->rowString($row, 'schema_type'),
            'author_id' => $this->rowNullableString($row, 'author_id'),
            'author_name' => $this->rowString($row, 'author_name'),
            'published_at' => $this->rowNullableString($row, 'published_at'),
            'created_at' => $this->rowString($row, 'created_at'),
            'updated_at' => $this->rowString($row, 'updated_at'),
            'revision' => (int) $revision,
            'placement' => $this->rowString($row, 'placement'),
            'password_hash' => $this->rowNullableString($row, 'password_hash'),
            'buttons_json' => $this->rowString($row, 'buttons_json'),
            'video_path' => $this->rowNullableString($row, 'video_path'),
            'show_contact_form' => $this->rowInt($row, 'show_contact_form'),
            'featured_media_id' => $this->rowNullableString($row, 'featured_media_id'),
            'tags' => $this->tagsFor($id),
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Page column has an invalid storage type.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Nullable page column has an invalid storage type.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Page column has an invalid storage type.');
        }

        return (int) $value;
    }

    private function assertSlugAvailable(string $slug, string $excludedId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM pages WHERE slug = :slug AND id <> :excluded_id LIMIT 1');
        $statement->execute(['slug' => $slug, 'excluded_id' => $excludedId]);
        if ($statement->fetchColumn() !== false) {
            throw new InvalidArgumentException('Slug is already in use by another page.');
        }
    }

    public function homepageId(): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute(['key' => 'homepage_id']);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function assertNotHomepage(string $id, string $action): void
    {
        if ($this->homepageId() === $id) {
            throw new InvalidArgumentException(
                'The configured homepage cannot be ' . $action . ' until another homepage is selected.'
            );
        }
    }


    private function conflictFor(string $id): WriteConflict
    {
        $statement = $this->pdo->prepare('SELECT revision FROM pages WHERE id = :id');
        $statement->execute(['id' => $id]);
        $revision = $statement->fetchColumn();
        if ($revision === false) {
            return WriteConflict::missing();
        }
        if (!is_int($revision) && !is_string($revision)) {
            throw new RuntimeException('Page revision has an invalid storage type.');
        }

        return WriteConflict::stale((int) $revision);
    }

    /** @param list<string> $tags */
    private function syncTags(string $pageId, array $tags): void
    {
        $delete = $this->pdo->prepare('DELETE FROM page_tags WHERE page_id = :page_id');
        $delete->execute(['page_id' => $pageId]);

        $find = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $insert = $this->pdo->prepare('INSERT INTO tags (id, name) VALUES (:id, :name)');
        $link = $this->pdo->prepare('INSERT INTO page_tags (page_id, tag_id) VALUES (:page_id, :tag_id)');
        foreach ($tags as $name) {
            $find->execute(['name' => $name]);
            $tagId = $find->fetchColumn();
            if ($tagId === false) {
                $tagId = bin2hex(random_bytes(16));
                $insert->execute(['id' => $tagId, 'name' => $name]);
            } elseif (!is_string($tagId)) {
                throw new RuntimeException('Tag ID has an invalid storage type.');
            }
            $link->execute(['page_id' => $pageId, 'tag_id' => $tagId]);
        }
    }

    /** @return list<string> */
    private function tagsFor(string $pageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tags.name FROM tags INNER JOIN page_tags ON page_tags.tag_id = tags.id '
            . 'WHERE page_tags.page_id = :page_id ORDER BY tags.rowid'
        );
        $statement->execute(['page_id' => $pageId]);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);
        $tags = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Tag name has an invalid storage type.');
            }
            $tags[] = $value;
        }

        return $tags;
    }

    private function throwSlugCollision(Throwable $exception): void
    {
        if ($exception instanceof PDOException && str_contains($exception->getMessage(), 'pages.slug')) {
            throw new InvalidArgumentException('Slug is already in use by another page.', 0, $exception);
        }
    }
}
