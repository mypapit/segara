<?php

declare(strict_types=1);

namespace Segara\Cache;

/**
 * Opt-in file cache for anonymous, published HTML only. Disabled by default.
 * Entries are grouped by namespace; only the public namespaces may ever be
 * stored, so admin, private, contact, and error responses are structurally
 * excluded from the cache.
 */
final class PageCache
{
    /** @var list<string> */
    private const CACHEABLE = ['public', 'home'];

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $enabled = false,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function get(string $namespace, string $key): ?string
    {
        if (!$this->enabled || !in_array($namespace, self::CACHEABLE, true)) {
            return null;
        }
        $path = $this->path($namespace, $key);
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function set(string $namespace, string $key, string $html): void
    {
        if (!$this->enabled || !in_array($namespace, self::CACHEABLE, true)) {
            return;
        }
        $path = $this->path($namespace, $key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $html, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function purge(?string $namespace = null): void
    {
        if ($namespace === null) {
            $this->removeChildren(rtrim($this->cacheDir, '/'));

            return;
        }
        $target = rtrim($this->cacheDir, '/') . '/' . $namespace;
        if (is_dir($target)) {
            $this->removeTree($target);
        }
    }

    public static function key(string ...$parts): string
    {
        return hash('sha256', implode('|', $parts));
    }

    private function path(string $namespace, string $key): string
    {
        return rtrim($this->cacheDir, '/') . '/' . $namespace . '/' . $key . '.html';
    }

    private function removeChildren(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
