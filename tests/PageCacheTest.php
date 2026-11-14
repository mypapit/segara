<?php

declare(strict_types=1);

namespace Tests;

use Segara\Cache\Invalidator;
use Segara\Cache\PageCache;
use Tests\Support\TestCase;

final class PageCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/segara-cache-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
        parent::tearDown();
    }

    public function testRoundTripWhenEnabled(): void
    {
        $cache = new PageCache($this->cacheDir, true);
        $key = PageCache::key('clarity', 'about', '3', '1', 'en');
        $cache->set('public', $key, '<html>hello</html>');

        self::assertSame('<html>hello</html>', $cache->get('public', $key));
    }

    public function testDisabledCacheIsANoOp(): void
    {
        $cache = new PageCache($this->cacheDir, false);
        $key = PageCache::key('clarity', 'about', '3', '1', 'en');
        $cache->set('public', $key, '<html>hello</html>');

        self::assertNull($cache->get('public', $key));
        self::assertSame([], glob($this->cacheDir . '/*') ?: []);
    }

    public function testAdminAndPrivateNamespacesAreNeverStored(): void
    {
        $cache = new PageCache($this->cacheDir, true);
        $cache->set('admin', PageCache::key('a'), 'secret');
        $cache->set('private', PageCache::key('b'), 'secret');
        $cache->set('error', PageCache::key('c'), 'secret');

        self::assertNull($cache->get('admin', PageCache::key('a')));
        self::assertNull($cache->get('private', PageCache::key('b')));
        self::assertSame([], glob($this->cacheDir . '/*') ?: []);
    }

    public function testPurgeNamespaceRemovesOnlyMatchingEntries(): void
    {
        $cache = new PageCache($this->cacheDir, true);
        $publicKey = PageCache::key('clarity', 'about', '3', '1', 'en');
        $homeKey = PageCache::key('clarity', 'home', '3', '1', 'en');
        $cache->set('public', $publicKey, 'page');
        $cache->set('home', $homeKey, 'home');

        $cache->purge('public');
        self::assertNull($cache->get('public', $publicKey));
        self::assertSame('home', $cache->get('home', $homeKey));
    }

    public function testInvalidatorPurgesStaleEntryAfterEdit(): void
    {
        $cache = new PageCache($this->cacheDir, true);
        $invalidator = new Invalidator($cache);

        $cache->set('public', PageCache::key('clarity', 'about', '1', '1', 'en'), 'revision 1');
        // An edit bumps the revision and purges the affected namespace.
        $invalidator->invalidate('public');
        self::assertNull($cache->get('public', PageCache::key('clarity', 'about', '1', '1', 'en')));

        $cache->set('public', PageCache::key('clarity', 'about', '2', '1', 'en'), 'revision 2');
        self::assertSame('revision 2', $cache->get('public', PageCache::key('clarity', 'about', '2', '1', 'en')));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
