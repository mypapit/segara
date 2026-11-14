<?php

declare(strict_types=1);

namespace Segara\View;

use InvalidArgumentException;
use Segara\Config;

final class Links
{
    public function __construct(private readonly Config $config)
    {
    }

    public function path(string $path = '/'): string
    {
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '?') || str_contains($path, '#')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
        ) {
            throw new InvalidArgumentException('Link path must be a local, normalized URL path.');
        }

        foreach (explode('/', substr($path, 1)) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/')
                || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            ) {
                throw new InvalidArgumentException('Link path contains an invalid segment.');
            }
        }

        return $this->config->basePath . $path;
    }

    public function url(string $path = '/'): string
    {
        return $this->config->baseUrl . $this->path($path);
    }
}
