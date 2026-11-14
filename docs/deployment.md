# Deployment

Segara is a portable PHP 8.4+ CMS with a single `public/` document root. All
data — the SQLite database, `uploads/`, and `settings/` — lives **outside** the
document root and is never web-accessible.

## Directory layout on the server

```
/var/www/segara/
├── public/          ← document root (index.php, admin.php, media.php, assets)
├── src/             ← application code (outside the document root)
├── settings/        ← config.php (secrets live here, never in public/)
├── store/           ← segara.sqlite, sessions/, logs/, backups/
├── uploads/         ← media files, served only through media.php
├── vendor/          ← Composer dependencies
└── bin/             ← CLI tools (setup, migrate, backup, restore, ...)
```

## 1. Install dependencies

Copy the project (including `vendor/`) to the server, or build it there:

```sh
composer install --no-dev --optimize-autoloader
```

## 2. Configure

```sh
php bin/setup
```

This writes `settings/config.php` (canonical `base_url`, captcha keys, session
settings) and creates the store. The canonical origin must be the HTTPS URL.

## 3. Web server

**Apache** — use `deploy/apache-vhost.conf` (or `public/.htaccess` on shared
hosting without a vhost). Enable `mod_rewrite` and `mod_ssl`.

**Nginx** — use `deploy/nginx-php-fpm.conf`. Nginx never reads `.htaccess`.

Both route clean URLs through `index.php`, serve `admin.php` and `media.php`
directly, deny hidden files, and never execute PHP under `documents/` or
`videos/`.

**Without clean-URL rewriting**, use the query fallback:

```
https://example.com/index.php?page=about
https://example.com/index.php?page=about&preview=1
```

## 4. HTTPS and administration

- Production admin requires HTTPS; enforce it with the redirect blocks above.
- Optional first gate: an external htpasswd file **outside** the document root,
  layered in front of the application login (see the commented blocks in both
  templates). Create it with `htpasswd -c /etc/apache2/segara-htpasswd admin`.

## 5. Permissions

The web server needs **write access only** to `store/` and `uploads/`. The rest
is read-only. Never use `0777`; `0755` for directories and `0644` for files is
sufficient, with the store/uploads directories owned by the PHP-FPM/Apache user
(e.g. `www-data`).

## 6. Verification

```sh
apache2ctl configtest      # expect: Syntax OK
nginx -t                   # expect: syntax is ok / test is successful
```

Record `NOT RUN` when the matching server is not installed on the build host.
