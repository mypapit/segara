<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\Storage\WriteConflict;
use Tests\Support\TestCase;

final class PageRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
    }

    public function testCreateAppliesDefaultsAndPersistsAuthorSnapshotAndTags(): void
    {
        $before = gmdate('Y-m-d\TH:i:s\Z');
        $page = $this->pages->create([
            'title' => 'Café Notes',
            'body_markdown' => 'A **bold** claim.',
            'author_id' => null,
            'author_name' => 'Site Administrator',
            'tags' => ['News', 'Coffee'],
        ]);
        $after = gmdate('Y-m-d\TH:i:s\Z');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $page['id']);
        self::assertSame('cafe-notes', $page['slug']);
        self::assertSame('draft', $page['state']);
        self::assertSame(1, $page['revision']);
        self::assertSame('WebPage', $page['schema_type']);
        self::assertSame('none', $page['placement']);
        self::assertSame('Site Administrator', $page['author_name']);
        self::assertNull($page['author_id']);
        self::assertNull($page['published_at']);
        self::assertSame(['News', 'Coffee'], $page['tags']);
        self::assertGreaterThanOrEqual($before, $page['created_at']);
        self::assertLessThanOrEqual($after, $page['created_at']);
        self::assertSame($page['created_at'], $page['updated_at']);
        self::assertSame(0, (new DateTimeImmutable($page['created_at']))->getOffset());
        self::assertSame($page, $this->pages->getById($page['id']));
        self::assertSame($page, $this->pages->getBySlug('cafe-notes'));
        self::assertNull($this->pages->getById(str_repeat('0', 32)));
        self::assertNull($this->pages->getBySlug('missing'));
    }

    public function testCreateUsesCurrentUtcPublicationTimeAndStableIdAcrossEdits(): void
    {
        $page = $this->pages->create($this->pageData(['state' => 'published']));
        self::assertNotNull($page['published_at']);
        self::assertSame(0, (new DateTimeImmutable($page['published_at']))->getOffset());

        $updated = $this->pages->update($page['id'], 1, ['title' => 'Renamed', 'slug' => 'renamed']);

        self::assertSame($page['id'], $updated['id']);
        self::assertSame($page['created_at'], $updated['created_at']);
        self::assertSame(2, $updated['revision']);
        self::assertSame('renamed', $updated['slug']);
        self::assertSame($page['published_at'], $updated['published_at']);
        self::assertSame($page['author_name'], $updated['author_name']);
    }

    public function testSlugCollisionIsClearAndNeverReplacesExistingPage(): void
    {
        $original = $this->pages->create($this->pageData());

        try {
            $this->pages->create($this->pageData(['slug' => 'example-title']));
            self::fail('A duplicate slug was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Slug is already in use', $exception->getMessage());
        }

        $other = $this->pages->create($this->pageData(['title' => 'Other', 'slug' => 'other']));
        try {
            $this->pages->update($other['id'], 1, ['slug' => 'example-title']);
            self::fail('An update replaced another page slug.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Slug is already in use', $exception->getMessage());
        }

        self::assertSame($original, $this->pages->getById($original['id']));
        $unchangedOther = $this->pages->getById($other['id']);
        self::assertNotNull($unchangedOther);
        self::assertSame('other', $unchangedOther['slug']);
    }

    #[DataProvider('reservedSlugs')]
    public function testReservedSlugsAreRejected(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $this->pages->create($this->pageData(['slug' => $slug]));
    }

    /** @return iterable<string, array{string}> */
    public static function reservedSlugs(): iterable
    {
        foreach (['admin', 'admin.php', 'media', 'assets', 'rss.xml', 'sitemap.xml', 'robots.txt', 'llms.txt', 'documents', 'videos'] as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('invalidEnums')]
    public function testInvalidEnumsAreRejected(string $field, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pages->create($this->pageData([$field => $value]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEnums(): iterable
    {
        yield 'state' => ['state', 'archived'];
        yield 'schema' => ['schema_type', 'Person'];
        yield 'placement' => ['placement', 'sidebar'];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidContent')]
    public function testContentBoundariesAreEnforced(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pages->create($this->pageData($changes));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidContent(): iterable
    {
        yield 'missing title' => [['title' => '  ']];
        yield 'long title' => [['title' => str_repeat('é', 201)]];
        yield 'long description' => [['description' => str_repeat('x', 501)]];
        yield 'large body' => [['body_markdown' => str_repeat('x', 1048577)]];
        yield 'too many tags' => [['tags' => array_map(static fn (int $n): string => 'tag' . $n, range(1, 21))]];
        yield 'invalid tags' => [['tags' => ['ok', 7]]];
        yield 'private without hash' => [['state' => 'private', 'password_hash' => null]];
        yield 'private plaintext password' => [['state' => 'private', 'password_hash' => 'secret']];
    }

    public function testMaximumContentLengthsAreAccepted(): void
    {
        $page = $this->pages->create($this->pageData([
            'title' => str_repeat('é', 200),
            'description' => str_repeat('d', 500),
            'body_markdown' => str_repeat('b', 1048576),
            'tags' => array_map(static fn (int $n): string => 'tag' . $n, range(1, 20)),
        ]));

        self::assertSame(200, mb_strlen($page['title']));
        self::assertSame(1048576, strlen($page['body_markdown']));
        self::assertCount(20, $page['tags']);
    }

    public function testOnlyOneRevisionOneUpdateSucceeds(): void
    {
        $page = $this->pages->create($this->pageData());
        $first = $this->pages->update($page['id'], 1, ['title' => 'First writer']);

        try {
            $this->pages->update($page['id'], 1, ['title' => 'Stale writer']);
            self::fail('A stale update overwrote a newer revision.');
        } catch (WriteConflict $exception) {
            self::assertTrue($exception->isStale());
            self::assertFalse($exception->isMissing());
            self::assertSame(2, $exception->currentRevision);
        }

        $current = $this->pages->getById($page['id']);
        self::assertNotNull($current);
        self::assertSame('First writer', $current['title']);
        self::assertSame(2, $current['revision']);
        self::assertSame(2, $first['revision']);
    }

    public function testMissingAndDeletedUpdatesHaveDistinctConflicts(): void
    {
        try {
            $this->pages->update(str_repeat('a', 32), 1, ['title' => 'Missing']);
            self::fail('A missing page was updated.');
        } catch (WriteConflict $exception) {
            self::assertTrue($exception->isMissing());
            self::assertFalse($exception->isStale());
        }

        $page = $this->pages->create($this->pageData());
        $this->pages->delete($page['id'], 1);
        $this->expectException(WriteConflict::class);
        $this->expectExceptionMessage('no longer exists');
        $this->pages->update($page['id'], 1, ['title' => 'Deleted']);
    }

    public function testTransitionIncrementsRevisionAndEnforcesPrivatePassword(): void
    {
        $page = $this->pages->create($this->pageData());
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);

        $private = $this->pages->transition($page['id'], 1, 'private', $hash);
        self::assertSame('private', $private['state']);
        self::assertSame($hash, $private['password_hash']);
        self::assertSame(2, $private['revision']);

        $published = $this->pages->transition($page['id'], 2, 'published');
        self::assertSame('published', $published['state']);
        self::assertNotNull($published['published_at']);
        self::assertSame(3, $published['revision']);

        $this->expectException(InvalidArgumentException::class);
        $this->pages->transition($page['id'], 3, 'invalid');
    }

    public function testHomepageCannotBeUnpublishedOrDeletedUntilReplacementIsSelected(): void
    {
        $page = $this->pages->create($this->pageData(['state' => 'published']));
        $replacement = $this->pages->create($this->pageData(['title' => 'Replacement', 'state' => 'published']));
        $this->setHomepage($page['id']);

        foreach (['draft', 'private', 'trash'] as $state) {
            try {
                $this->pages->transition($page['id'], 1, $state, password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]));
                self::fail('The configured homepage was unpublished.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('homepage', $exception->getMessage());
            }
        }

        try {
            $this->pages->update($page['id'], 1, ['state' => 'draft']);
            self::fail('An update unpublished the configured homepage.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('homepage', $exception->getMessage());
        }

        try {
            $this->pages->delete($page['id'], 1);
            self::fail('The configured homepage was deleted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('homepage', $exception->getMessage());
        }

        $this->setHomepage($replacement['id']);
        $deletedId = $page['id'];
        $this->pages->delete($deletedId, 1);
        self::assertNull($this->pages->getById($deletedId));
    }

    public function testConfiguredDraftHomepageCannotTransitionToTrash(): void
    {
        $page = $this->pages->create($this->pageData());
        $this->setHomepage($page['id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('homepage');
        $this->pages->transition($page['id'], 1, 'trash');
    }

    public function testDeleteAndTransitionUseRevisionChecks(): void
    {
        $page = $this->pages->create($this->pageData());
        $this->pages->transition($page['id'], 1, 'trash');

        try {
            $this->pages->delete($page['id'], 1);
            self::fail('A stale delete removed the page.');
        } catch (WriteConflict $exception) {
            self::assertTrue($exception->isStale());
        }
        self::assertNotNull($this->pages->getById($page['id']));

        $this->pages->delete($page['id'], 2);
        self::assertNull($this->pages->getById($page['id']));
    }

    public function testHomepageListingContainsOnlyPublishedPlacementsAndCapsAtTwenty(): void
    {
        $excluded = [
            $this->pageData(['title' => 'None', 'state' => 'published']),
            $this->pageData(['title' => 'Draft home', 'placement' => 'home']),
            $this->pageData(['title' => 'Trash featured', 'state' => 'trash', 'placement' => 'featured']),
            $this->pageData(['title' => 'Private home', 'state' => 'private', 'placement' => 'home', 'password_hash' => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4])]),
        ];
        foreach ($excluded as $data) {
            $this->pages->create($data);
        }

        for ($i = 1; $i <= 25; $i++) {
            $page = $this->pages->create($this->pageData([
                'title' => sprintf('Page %02d', $i),
                'state' => 'published',
                'placement' => $i === 1 ? 'featured' : 'home',
                'published_at' => sprintf('2026-01-%02dT00:00:00Z', $i),
            ]));
            self::assertSame(sprintf('2026-01-%02dT00:00:00Z', $i), $page['published_at']);
        }

        $listed = $this->pages->listHomepage();
        self::assertCount(20, $listed);
        self::assertSame('Page 01', $listed[0]['title']);
        self::assertSame('Page 25', $listed[1]['title']);
        self::assertSame('Page 07', $listed[19]['title']);
        foreach ($listed as $page) {
            self::assertSame('published', $page['state']);
            self::assertContains($page['placement'], ['home', 'featured']);
        }
    }

    public function testSqlMetacharactersAreStoredAsValues(): void
    {
        $title = "Robert'); DROP TABLE pages;--";
        $page = $this->pages->create($this->pageData(['title' => $title, 'author_name' => "Ad'min"]));

        $storedById = $this->pages->getById($page['id']);
        $storedBySlug = $this->pages->getBySlug($page['slug']);
        self::assertNotNull($storedById);
        self::assertNotNull($storedBySlug);
        self::assertSame($title, $storedById['title']);
        self::assertSame("Ad'min", $storedBySlug['author_name']);
        $statement = $this->pdo->query('SELECT COUNT(*) FROM pages');
        self::assertInstanceOf(PDOStatement::class, $statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed> */
    private function pageData(array $changes = []): array
    {
        return array_merge([
            'title' => 'Example Title',
            'author_name' => 'Administrator',
            'body_markdown' => '',
            'description' => '',
            'tags' => [],
        ], $changes);
    }

    private function setHomepage(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, revision, updated_at) VALUES (:key, :value, 1, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value, revision = settings.revision + 1, updated_at = excluded.updated_at'
        );
        $statement->execute(['key' => 'homepage_id', 'value' => $id, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
    }
}
