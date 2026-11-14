<?php

declare(strict_types=1);

namespace Segara\Blocks;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Pages\LocalResource;
use Segara\Storage\WriteConflict;
use Throwable;

/**
 * @phpstan-type Block array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}
 * @psalm-type Block = array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}
 */
final class BlockRepository
{
    private const LOCATIONS = ['left', 'right', 'footer'];
    private const TYPES = [
        'menu', 'images', 'rss', 'social', 'external-links', 'quick-facts',
        'custom-inline', 'copyright', 'address', 'blurb',
    ];

    /** @var list<string> */
    public const SOCIAL_PROVIDERS = [
        'x', 'github', 'twitch', 'facebook', 'instagram', 'threads', 'linkedin', 'youtube',
        'tiktok', 'reddit', 'bluesky', 'mastodon', 'researchgate', 'orcid', 'padlet', 'linktree', 'custom',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return Block
     */
    public function create(array $input): array
    {
        $location = $this->location($input['location'] ?? null);
        $type = $this->type($input['type'] ?? null);
        $requestedPosition = $this->position($input['position'] ?? null);
        $settingsInput = $input['settings'] ?? [];
        if (!is_array($settingsInput)) {
            throw new InvalidArgumentException('Block settings must be an object.');
        }
        $settings = $this->validateSettings($type, $settingsInput);

        $id = bin2hex(random_bytes(16));

        $this->pdo->beginTransaction();
        try {
            // Append: a caller-supplied position would let two blocks claim slot 0,
            // because the admin form no longer offers a position field.
            $position = $requestedPosition;
            if ($position === null) {
                $next = $this->pdo->prepare('SELECT COALESCE(MAX(position) + 1, 0) FROM blocks WHERE location = :location');
                $next->execute(['location' => $location]);
                $candidate = $next->fetchColumn();
                $position = is_int($candidate) ? $candidate : (is_string($candidate) ? (int) $candidate : 0);
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO blocks (id, location, position, type, settings_json, revision) '
                . 'VALUES (:id, :location, :position, :type, :settings, 1)'
            );
            $statement->execute([
                'id' => $id,
                'location' => $location,
                'position' => $position,
                'type' => $type,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $block = $this->getById($id);
        if ($block === null) {
            throw new RuntimeException('Created block could not be read back.');
        }

        return $block;
    }

    /** @return Block|null */
    public function getById(string $id): ?array
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, location, position, type, settings_json, revision FROM blocks WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Block row has an invalid storage type.');
        }

        return $this->hydrate($row);
    }

    /** @return list<Block> */
    public function listByLocation(string $location): array
    {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new InvalidArgumentException('Block location must be left, right, or footer.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, location, position, type, settings_json, revision FROM blocks WHERE location = :location ORDER BY position ASC, id ASC'
        );
        $statement->execute(['location' => $location]);
        $blocks = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Block row has an invalid storage type.');
            }
            $blocks[] = $this->hydrate($row);
        }

        return $blocks;
    }

    /** @return list<Block> */
    public function listAll(): array
    {
        $statement = $this->pdo->prepare('SELECT id, location, position, type, settings_json, revision FROM blocks ORDER BY location ASC, position ASC, id ASC');
        $statement->execute();
        $blocks = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Block row has an invalid storage type.');
            }
            $blocks[] = $this->hydrate($row);
        }

        return $blocks;
    }

    /**
     * Every region in a single read, so the public layout does not issue one
     * query per sidebar.
     *
     * @return array{left: list<Block>, right: list<Block>, footer: list<Block>}
     */
    public function listGrouped(): array
    {
        /** @var array{left: list<Block>, right: list<Block>, footer: list<Block>} $grouped */
        $grouped = ['left' => [], 'right' => [], 'footer' => []];

        foreach ($this->listAll() as $block) {
            $location = $block['location'];
            if ($location === 'left' || $location === 'right' || $location === 'footer') {
                $grouped[$location][] = $block;
            }
        }

        return $grouped;
    }

    /**
     * Revalidate and rewrite one block, guarded by its revision.
     *
     * @param array<string, mixed> $input
     * @return Block
     */
    public function update(string $id, int $expectedRevision, array $input): array
    {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be a positive integer.');
        }
        $existing = $this->getById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Block not found.');
        }

        $location = $this->location($input['location'] ?? $existing['location']);
        $type = $this->type($input['type'] ?? $existing['type']);
        $settingsInput = $input['settings'] ?? [];
        if (!is_array($settingsInput)) {
            throw new InvalidArgumentException('Block settings must be an object.');
        }
        $settings = $this->validateSettings($type, $settingsInput);

        $this->pdo->beginTransaction();
        try {
            $moved = $location !== $existing['location'];
            $position = $existing['position'];
            if ($moved) {
                $next = $this->pdo->prepare('SELECT COALESCE(MAX(position) + 1, 0) FROM blocks WHERE location = :location');
                $next->execute(['location' => $location]);
                $candidate = $next->fetchColumn();
                $position = is_int($candidate) ? $candidate : (is_string($candidate) ? (int) $candidate : 0);
            }

            $statement = $this->pdo->prepare(
                'UPDATE blocks SET location = :location, position = :position, type = :type, settings_json = :settings_json, '
                . 'revision = revision + 1 WHERE id = :id AND revision = :expected_revision'
            );
            $statement->execute([
                'location' => $location,
                'position' => $position,
                'type' => $type,
                'settings_json' => json_encode($settings, JSON_THROW_ON_ERROR),
                'id' => $id,
                'expected_revision' => $expectedRevision,
            ]);
            if ($statement->rowCount() !== 1) {
                throw WriteConflict::stale($expectedRevision);
            }
            if ($moved) {
                $this->renumber($existing['location']);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $block = $this->getById($id);
        if ($block === null) {
            throw new RuntimeException('Updated block could not be read back.');
        }

        return $block;
    }

    /** Rewrite one region's positions to 0..n-1 in stored order. */
    private function renumber(string $location): void
    {
        $ids = [];
        $select = $this->pdo->prepare('SELECT id FROM blocks WHERE location = :location ORDER BY position ASC, id ASC');
        $select->execute(['location' => $location]);
        while (($id = $select->fetchColumn()) !== false) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        $update = $this->pdo->prepare('UPDATE blocks SET position = :position WHERE id = :id');
        foreach ($ids as $index => $blockId) {
            $update->execute(['position' => $index, 'id' => $blockId]);
        }
    }

    public function delete(string $id): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid block reference.');
        }
        $statement = $this->pdo->prepare('DELETE FROM blocks WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Block not found.');
        }
    }

    /**
     * Persist a complete, duplicate-free ordering for every block in one location.
     * Validates untrusted input at the boundary.
     *
     * @param array<mixed> $orderedIds
     */
    public function reorder(string $location, array $orderedIds): void
    {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new InvalidArgumentException('Block location must be left, right, or footer.');
        }
        if (!array_is_list($orderedIds) || count($orderedIds) === 0 || count($orderedIds) > 1000) {
            throw new InvalidArgumentException('Ordered block IDs must be a non-empty list.');
        }

        $existing = $this->listByLocation($location);
        $existingIds = [];
        foreach ($existing as $block) {
            $existingIds[$block['id']] = true;
        }
        if (count($orderedIds) !== count($existingIds)) {
            throw new InvalidArgumentException('The order must include every block in this location.');
        }

        $seen = [];
        foreach ($orderedIds as $id) {
            if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid block reference.');
            }
            if (!isset($existingIds[$id])) {
                throw new InvalidArgumentException('A block in the order does not belong to this location.');
            }
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('The order contains a duplicate block.');
            }
            $seen[$id] = true;
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE blocks SET position = :position, revision = revision + 1 WHERE id = :id AND location = :location'
            );
            foreach ($orderedIds as $position => $id) {
                $statement->execute(['position' => $position, 'id' => $id, 'location' => $location]);
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
     * @param array<array-key, mixed> $row
     * @return Block
     */
    private function hydrate(array $row): array
    {
        $position = $row['position'] ?? null;
        if (!is_int($position) && !is_string($position)) {
            throw new RuntimeException('Block position has an invalid storage type.');
        }
        $revision = $row['revision'] ?? null;
        if (!is_int($revision) && !is_string($revision)) {
            throw new RuntimeException('Block revision has an invalid storage type.');
        }

        return [
            'id' => $this->rowString($row, 'id'),
            'location' => $this->rowString($row, 'location'),
            'position' => (int) $position,
            'type' => $this->rowString($row, 'type'),
            'settings_json' => $this->rowString($row, 'settings_json'),
            'revision' => (int) $revision,
        ];
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array<string, mixed>
     */
    private function validateSettings(string $type, array $settings): array
    {
        return match ($type) {
            'menu' => ['menu_id' => $this->id($settings['menu_id'] ?? null, 'Menu')],
            'images' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'image_ids' => $this->idList($settings['image_ids'] ?? []),
            ],
            'rss' => ['title' => $this->optionalText($settings['title'] ?? null)],
            'social' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'networks' => $this->networks($settings['networks'] ?? []),
            ],
            'external-links' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'links' => $this->links($settings['links'] ?? []),
            ],
            'quick-facts' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'facts' => $this->facts($settings['facts'] ?? []),
            ],
            'custom-inline' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'html' => InlineHtml::sanitize($this->text($settings['html'] ?? '', 'HTML')),
            ],
            'copyright' => ['text' => $this->text($settings['text'] ?? '', 'Copyright')],
            'address' => ['text' => $this->text($settings['text'] ?? '', 'Address')],
            'blurb' => [
                'title' => $this->optionalText($settings['title'] ?? null),
                'text' => $this->text($settings['text'] ?? '', 'Blurb'),
                'image_id' => $this->optionalId($settings['image_id'] ?? null),
            ],
            default => throw new InvalidArgumentException('Block type is not supported.'),
        };
    }

    /** @return list<array{provider: string, url: string}> */
    private function networks(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 30) {
            throw new InvalidArgumentException('Social networks must be a list of at most 30 entries.');
        }
        $networks = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Each social network must be an object.');
            }
            $provider = $entry['provider'] ?? null;
            if (!is_string($provider) || !in_array($provider, self::SOCIAL_PROVIDERS, true)) {
                throw new InvalidArgumentException('Social provider is not supported.');
            }
            $url = $entry['url'] ?? null;
            if (!is_string($url)) {
                throw new InvalidArgumentException('Social network URL must be a string.');
            }
            $networks[] = ['provider' => $provider, 'url' => LocalResource::customUrl($url)];
        }

        return $networks;
    }

    /** @return list<array{label: string, url: string}> */
    private function links(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new InvalidArgumentException('External links must be a list of at most 50 entries.');
        }
        $links = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Each external link must be an object.');
            }
            $label = $this->text($entry['label'] ?? '', 'Link label');
            $url = $entry['url'] ?? null;
            if (!is_string($url)) {
                throw new InvalidArgumentException('External link URL must be a string.');
            }
            $links[] = ['label' => $label, 'url' => LocalResource::customUrl($url)];
        }

        return $links;
    }

    /** @return list<array{text: string, bullet: string}> */
    private function facts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new InvalidArgumentException('Quick facts must be a list of at most 50 entries.');
        }
        $facts = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Each quick fact must be an object.');
            }
            $facts[] = [
                'text' => $this->text($entry['text'] ?? '', 'Fact'),
                'bullet' => $this->optionalText($entry['bullet'] ?? ''),
            ];
        }

        return $facts;
    }

    /** @return list<string> */
    private function idList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new InvalidArgumentException('Image IDs must be a list of at most 20 entries.');
        }
        $ids = [];
        foreach ($value as $item) {
            $ids[] = $this->id($item, 'Image');
        }

        return $ids;
    }

    private function location(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::LOCATIONS, true)) {
            throw new InvalidArgumentException('Block location must be left, right, or footer.');
        }

        return $value;
    }

    private function type(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::TYPES, true)) {
            throw new InvalidArgumentException('Block type is not supported.');
        }

        return $value;
    }

    private function position(mixed $value): ?int
    {
        if ($value === null) {
            return null; // caller wants the next free slot
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Position must be an integer.');
        }
        $position = (int) $value;
        if ($position < 0 || $position > 100000) {
            throw new InvalidArgumentException('Position must be between 0 and 100000.');
        }

        return $position;
    }

    private function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' must be a valid identifier.');
        }

        return $value;
    }

    private function optionalId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->id($value, 'Image');
    }

    private function text(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > 5000 || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException($label . ' must be non-empty UTF-8 of at most 5000 characters.');
        }

        return $value;
    }

    private function optionalText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 500 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Title must be valid UTF-8 of at most 500 characters.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Block column has an invalid storage type.');
        }

        return $value;
    }
}
