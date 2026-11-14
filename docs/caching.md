# Caching

Segara ships with OPcache as the baseline and an **opt-in** file cache that is
**disabled by default**. Nothing in a default install writes a page cache.

## What may be cached

Only anonymous, published HTML pages and the public home page are eligible.
The cache namespace allowlist is `public` and `home`. The following are never
stored, regardless of configuration:

- admin pages and login
- previews
- private (password-protected) pages
- contact form submissions
- error (4xx/5xx) responses

## Cache key

Entries are keyed by theme, page slug, page revision, settings revision, and
locale. A key is built with `PageCache::key(...)`:

```php
$key = PageCache::key($theme, $slug, (string) $revision, (string) $settingsRevision, $locale);
```

A change to any component produces a different key, so stale variants are
simply never read. On mutation, purge the affected namespace explicitly.

## Enabling

Construct the cache with `enabled = true` and a directory outside `public/`:

```php
$cache = new PageCache('/path/to/store/cache', true);
```

There is no Memcached or distributed cache, by design.

## Invalidation

After any page, menu, block, settings, or media mutation, purge from the same
maintenance workflow:

```php
$invalidator = new Invalidator($cache);
$invalidator->invalidate('public');   // purge public pages
$invalidator->invalidate('home');     // purge the home page
$invalidator->invalidateAll();        // purge everything
```

## Benchmarking

`bin/benchmark` measures median requests per second against a local endpoint
and never enables caching:

```sh
php bin/benchmark --url=http://127.0.0.1:8000/ --requests=200
```
