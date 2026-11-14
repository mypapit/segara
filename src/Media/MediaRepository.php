<?php

declare(strict_types=1);

namespace Segara\Media;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * @phpstan-type Media array{id: string, original_name: string, stored_path: string, mime: string, size: int, alt_text: string, decorative: int, created_at: string, updated_at: string}
 * @psalm-type Media = array{id: string, original_name: string, stored_path: string, mime: string, size: int, alt_text: string, decorative: int, created_at: string, updated_at: string}
 */
final class MediaRepository
{
    private const COLUMNS = 'id, original_name, stored_path, mime, size, alt_text, decorative, created_at, updated_at';
    private const MAX_BYTES = 5242880;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return Media
     */
    public function create(array $input): array
    {
        $originalName = $this->originalName($input['original_name'] ?? null);
        $storedPath = $this->storedPath($input['stored_path'] ?? null);
        $mime = $this->mime($input['mime'] ?? null);
        $size = $this->size($input['size'] ?? null);
        $altText = $this->altText($input['alt_text'] ?? null);
        $decorative = $this->decorative($input['decorative'] ?? 0);
        if ($decorative === 0 && trim($altText) === '') {
            throw new InvalidArgumentException('Alt text is required for informative images; mark decorative images explicitly instead.');
        }

        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->pdo->prepare(
            'INSERT INTO media (id, original_name, stored_path, mime, size, alt_text, decorative, created_at, updated_at) '
            . 'VALUES (:id, :original_name, :stored_path, :mime, :size, :alt_text, :decorative, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'mime' => $mime,
            'size' => $size,
            'alt_text' => $altText,
            'decorative' => $decorative,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $media = $this->getById($id);
        if ($media === null) {
            throw new RuntimeException('Created media could not be read back.');
        }

        return $media;
    }

    /** @return Media|null */
    public function getById(string $id): ?array
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Media row has an invalid storage type.');
        }

        return $this->hydrate($row);
    }

    /** @return list<Media> */
    public function list(): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM media ORDER BY created_at DESC, id ASC');
        $statement->execute();
        $media = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Media row has an invalid storage type.');
            }
            $media[] = $this->hydrate($row);
        }

        return $media;
    }

    /**
     * Delete media only when it is not referenced by any page and the stored
     * file can be removed; otherwise retain the metadata for a retry.
     */
    public function delete(string $id, string $uploadRoot): void
    {
        $media = $this->getById($id);
        if ($media === null) {
            throw new InvalidArgumentException('Media not found.');
        }
        if ($this->isReferenced($id)) {
            throw new InvalidArgumentException('Media is still referenced by a page and cannot be deleted.');
        }

        $storedPath = $media['stored_path'];
        if (preg_match('/\A[0-9a-f]{32}\.(jpg|png|webp)\z/D', $storedPath) !== 1) {
            throw new InvalidArgumentException('Stored filename is invalid.');
        }
        $file = $uploadRoot . '/' . $storedPath;
        if (is_file($file) && !@unlink($file)) {
            throw new RuntimeException('The media file could not be removed; metadata was retained.');
        }

        $statement = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Media could not be deleted.');
        }
    }

    public function isReferenced(string $id): bool
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pages WHERE featured_media_id = :id OR body_markdown LIKE :pattern'
        );
        $statement->execute(['id' => $id, 'pattern' => '%media.php?id=' . $id . '%']);
        $count = $statement->fetchColumn();

        return $count !== false && (int) $count > 0;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return Media
     */
    private function hydrate(array $row): array
    {
        $size = $row['size'] ?? null;
        if (!is_int($size) && !is_string($size)) {
            throw new RuntimeException('Media size has an invalid storage type.');
        }
        $decorative = $row['decorative'] ?? null;
        if (!is_int($decorative) && !is_string($decorative)) {
            throw new RuntimeException('Media decorative flag has an invalid storage type.');
        }

        return [
            'id' => $this->rowString($row, 'id'),
            'original_name' => $this->rowString($row, 'original_name'),
            'stored_path' => $this->rowString($row, 'stored_path'),
            'mime' => $this->rowString($row, 'mime'),
            'size' => (int) $size,
            'alt_text' => $this->rowString($row, 'alt_text'),
            'decorative' => (int) $decorative,
            'created_at' => $this->rowString($row, 'created_at'),
            'updated_at' => $this->rowString($row, 'updated_at'),
        ];
    }

    private function originalName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > 255 || basename($value) !== $value
            || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException('Original filename must be a plain, valid UTF-8 basename of at most 255 characters.');
        }

        return $value;
    }

    private function storedPath(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{32}\.(jpg|png|webp)\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Stored filename is invalid.');
        }

        return $value;
    }

    private function mime(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::MIMES, true)) {
            throw new InvalidArgumentException('Only JPEG, PNG, and WebP images are supported.');
        }

        return $value;
    }

    private function size(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Image size is invalid.');
        }
        $size = (int) $value;
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Image must be between 1 byte and 5 MiB.');
        }

        return $size;
    }

    private function altText(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 500 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Alt text must be valid UTF-8 of at most 500 characters.');
        }

        return $value;
    }

    private function decorative(mixed $value): int
    {
        if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            throw new InvalidArgumentException('Decorative flag must be 0 or 1.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Media column has an invalid storage type.');
        }

        return $value;
    }
}
