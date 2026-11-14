<?php

declare(strict_types=1);

namespace Segara\Media;

use InvalidArgumentException;
use RuntimeException;

final class ImageUpload
{
    private const MAX_BYTES = 5242880;

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $uploadRoot)
    {
    }

    public function root(): string
    {
        return $this->uploadRoot;
    }

    /**
     * Validate an uploaded image and store it under a random filename.
     *
     * @param array<string, mixed> $file A `$_FILES` entry: name, type, tmp_name, size, error.
     * @return array{stored_path: string, mime: string, size: int, original_name: string}
     */
    public function store(array $file): array
    {
        $this->prepareUploadRoot();

        $tmpName = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The upload failed or no file was received.');
        }
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('The uploaded file could not be verified.');
        }

        $validated = $this->validate($tmpName, $this->originalName($file['name'] ?? null), $this->size($file['size'] ?? null));

        $storedPath = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$validated['mime']];
        if (!move_uploaded_file($tmpName, $this->uploadRoot . '/' . $storedPath)) {
            throw new RuntimeException('The uploaded image could not be stored.');
        }

        return [
            'stored_path' => $storedPath,
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'original_name' => $validated['original_name'],
        ];
    }

    /**
     * Validate an image file by inspecting its contents. Works on any readable
     * file path; the upload origin is verified separately in `store`.
     *
     * @return array{mime: string, size: int, original_name: string}
     */
    public function validate(string $path, string $originalName, int $size): array
    {
        $this->size($size);
        $this->originalName($originalName);
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            throw new InvalidArgumentException('The file could not be read.');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($detected) || !isset(self::EXTENSIONS[$detected])) {
            throw new InvalidArgumentException('Only JPEG, PNG, and WebP images are supported.');
        }

        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException('The file is not a valid image.');
        }
        if (image_type_to_mime_type($info[2]) !== $detected) {
            throw new InvalidArgumentException('The file is not a valid image.');
        }

        return ['mime' => $detected, 'size' => $size, 'original_name' => $originalName];
    }

    private function size(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('The uploaded file size is invalid.');
        }
        $size = (int) $value;
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Image must be between 1 byte and 5 MiB.');
        }

        return $size;
    }

    private function originalName(mixed $value): string
    {
        if (!is_string($value) || $value === '' || basename($value) !== $value || str_contains($value, "\0")) {
            throw new InvalidArgumentException('The uploaded filename is invalid.');
        }

        return $value;
    }

    private function prepareUploadRoot(): void
    {
        if ($this->uploadRoot === '' || !str_starts_with($this->uploadRoot, '/')
            || str_contains($this->uploadRoot, "\0") || is_link($this->uploadRoot)
        ) {
            throw new InvalidArgumentException('Upload directory must be an absolute private path.');
        }
        if (!is_dir($this->uploadRoot)
            && !mkdir($this->uploadRoot, 0700, true)
            && !is_dir($this->uploadRoot)
        ) {
            throw new RuntimeException('Unable to create the upload directory.');
        }
        $realPath = realpath($this->uploadRoot);
        if ($realPath === false || !is_dir($realPath) || !is_writable($realPath)) {
            throw new RuntimeException('Upload directory is unavailable or unwritable.');
        }
    }
}
