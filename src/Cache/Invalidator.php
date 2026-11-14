<?php

declare(strict_types=1);

namespace Segara\Cache;

/**
 * Purge the page cache after mutations. Callers invoke this from the same
 * maintenance workflow that writes pages, menus, blocks, settings, or media.
 */
final class Invalidator
{
    public function __construct(private readonly PageCache $cache)
    {
    }

    public function invalidateAll(): void
    {
        $this->cache->purge();
    }

    public function invalidate(string $namespace): void
    {
        $this->cache->purge($namespace);
    }
}
