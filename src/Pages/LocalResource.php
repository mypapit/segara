<?php

declare(strict_types=1);

namespace Segara\Pages;

use InvalidArgumentException;

final class LocalResource
{
    private const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    /**
     * Validate an optional local video path. Empty/null means "no video".
     */
    public static function videoPath(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Video path must be a string.');
        }
        $path = self::safePath($value);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Local video must be an MP4 or WebM file.');
        }

        return $path;
    }

    /**
     * Validate a local document path (root-relative, no traversal or scheme).
     */
    public static function documentPath(string $value): string
    {
        return self::safePath($value);
    }

    /**
     * Validate a custom link target: an HTTP/HTTPS URL or a root-relative path.
     */
    public static function customUrl(string $value): string
    {
        if (str_starts_with($value, '/')) {
            return self::safePath($value);
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Button link must be an HTTP, HTTPS, or relative URL.');
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Button link must use the HTTP or HTTPS scheme.');
        }

        return $value;
    }

    private static function safePath(string $value): string
    {
        if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//')
            || str_contains($value, '\\') || str_contains($value, '?') || str_contains($value, '#')
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1
        ) {
            throw new InvalidArgumentException('Local resource path is invalid.');
        }

        foreach (explode('/', substr($value, 1)) as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || $decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/') || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            ) {
                throw new InvalidArgumentException('Local resource path contains an invalid segment.');
            }
        }

        return $value;
    }
}
