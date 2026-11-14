# Segara

Segara is a small PHP CMS: pages, menus, settings, and contact messages live in one SQLite file, and the HTML, JSON-LD, feeds, and `llms.txt` come from those same records.

Project home: <https://github.com/mypapit/segara/>

Segara is built for one person running one small website on their own server. Install is a file copy, one migration, and a `chown`; backup is a copy of `store/` and `uploads/`. There is no database daemon, build step, plugin system, or web installer.

## Features

### Pages and publishing

Each page carries a title, slug, description, tags, authors, publication date, a Schema.org type (default `WebPage`), and a placement (`none`, `home`, `featured`). Optional fields cover up to three buttons, a video path, featured media, and an inline contact form. The homepage shows at most 20 entries and has no pagination.

Four states drive visibility:

- **Published** — reachable at its slug.
- **Draft** — returns a 404 byte-identical to a missing page.
- **Trash** — same 404 as draft.
- **Private** — asks for a password before disclosing the title, body, summary, or any metadata, and is served uncached.

Save draft, Preview, and Publish are separate actions; preview renders through an authenticated route and leaves state unchanged.

### Blocks, menus, and navigation

Ten block types are available for the rails and footer: menu, images, RSS link, social links, external links, quick facts, custom inline text, copyright, address, and blurb. Blocks belong to a location (`left`, `right`, `footer`) rather than to a theme, so choosing a theme with fewer rails hides a block instead of deleting it. Ordering arrows move a block within its location, and saving is a separate step from editing.

Menus support nested items, editable labels, ordering, and targets that are either a page or a custom URL.

### Media

Uploads are limited to JPEG, PNG, and WebP up to 5 MiB each. Every upload either gets alt text or an explicit "decorative" flag. Files are served only through `media.php?id=<id>`, so `uploads/` itself is not web-reachable. Video and document files are not uploaded; they are placed on the server beside the document root and referenced by local path.

### Machine-readable output

Every HTML response carries JSON-LD using Schema.org types, plus Open Graph and Dublin Core tags built from the same values the reader sees. XML and text routes keep their own formats:

| Route | Contents |
|---|---|
| `/rss.xml` | Newest 20 published pages, with featured-media enclosures |
| `/sitemap.xml` | Canonical published URLs plus the home page |
| `/robots.txt` | Disallows admin paths, points at the sitemap |
| `/llms.txt` | Markdown index of published pages for LLM discovery |

The public site ships no client-side JavaScript: navigation, previews, and the contact form all work without it.

### Administration

The admin panel has six fixed destinations: Pages, Media, Settings, Menu, Admin, and Log out. Accounts are created inside the panel, and every administrator has identical rights; there are no roles, permission matrices, or public accounts.

Contact form submissions are stored in SQLite and read in the authenticated inbox. Segara sends no email at all, which is why there is no web password recovery.

What is on by default:

- CSRF tokens on every state-changing form.
- Login throttling, and sessions that expire after 30 minutes idle or 8 hours in total.
- CSP and security headers on every response.
- Markdown rendered by Parsedown in safe mode, with raw HTML escaped and the output run through an HTML sanitizer.
- A theme allowlist fixed in code, so an unknown theme id is rejected before it can reach a file lookup.

### Themes

Six server-rendered themes, switchable from Settings. Each is written against CSS custom properties and covered by contract tests, including PHPUnit checks that every palette meets WCAG AA contrast.

| Theme | Rails | Character |
|---|---|---|
| `clarity` | 0 | Single column, minimal |
| `aubergine` | 1 right | One rail for secondary content |
| `editorial` | 2 | Magazine layout |
| `scholar` | 1 | Warm paper, espresso ink, terracotta accent |
| `folio` | 1 right | Editorial derivative with the dashboard's old-style serif |
| `slate` | 0 | Bootstrap 5.3 derivative with a full-bleed gradient band |

### Command line

Privileged work runs on the command line, not in the admin panel:

| Command | Purpose |
|---|---|
| `bin/setup` | Write config, create the store, create the first admin |
| `bin/migrate` | Apply migrations from `migrations/` |
| `bin/check-environment` | Check PHP version, extensions, and directory layout |
| `bin/password-reset` | Reset an administrator password |
| `bin/backup` / `bin/restore` | Archive and restore store, uploads, and settings (SHA-256 manifest, path-traversal and checksum checks) |
| `bin/export-content` / `bin/import-content` | WXR export and import |
| `bin/rebuild-public-files` | Regenerate `sitemap.xml`, `robots.txt`, `llms.txt` |
| `bin/seed-blocks` | Seed default Quick Facts, RSS, and copyright blocks; refuses if any block exists |
| `bin/benchmark` | Timing run against the render pipeline |

### Caching

OPcache is the baseline. The optional file page cache is off by default, so a default install writes no cache at all; turned on, it stores anonymous published HTML keyed by theme, slug, page revision, settings revision, and locale. Admin pages, previews, private pages, contact submissions, and error responses are never stored.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.4.1 or newer |
| PHP extensions | `dom`, `fileinfo`, `intl`, `json`, `mbstring`, `pdo`, `pdo_sqlite`, `xml`, `xmlreader`, `xmlwriter`, `zip` |
| Composer | 2.x — needed only to build `vendor/`; a tree copied with `vendor/` needs none on the server |
| Web server | nginx with PHP-FPM, or Apache 2.4 with `mod_rewrite` and `mod_ssl` |
| Storage | SQLite through PDO; no MySQL or Postgres, and no database server to run |
| Transport | HTTPS in production |
| Writable paths | `store/` and `uploads/`, owned by the web-server user |
| Upload limit | 5 MiB per image; the nginx template sets `client_max_body_size 8m` |

Confirm them in one step:

```sh
php bin/check-environment
# or, after the docroot rename:
php bin/check-environment --public=/var/www/html/segara/public_html
```

The site renders from PHP and the SQLite file on each request; nothing else has to be running.

## Installing

Two paths: the deployment script, or the same steps by hand. Both assume a Debian or Ubuntu host with `nginx` and `php8.4-fpm` already installed.

### Option A — scripted deploy

```sh
git clone https://github.com/mypapit/segara.git   # access by invitation until the public release
cd segara
sudo SEGARA_BASE_URL=https://segara.lan \
     SEGARA_ADMIN_PASSWORD='a-long-password' \
     ./deploy/install.sh
```

The script copies the tree to `/var/www/html/segara` and renames `public/` to `public_html/`. It then creates `store/` and `uploads/`, writes `settings/config.php`, runs `check-environment`, migrates, creates the first admin, chowns the writable directories to `www-data`, installs the Apache `.htaccess` guards, and rebuilds the public metadata files.

Overrides: `SEGARA_SRC`, `SEGARA_DST`, `SEGARA_DOCROOT`, `SEGARA_WEB_USER`, `SEGARA_ADMIN_USER`, `SEGARA_ADMIN_SKIP=1` (migrate only), `SEGARA_SEED=1` (seed default blocks), `SEGARA_APACHE_GUARDS=0`.

### Option B — step by step

1. **Copy the files and rename the document root.**

   ```sh
   SRC=/path/to/segara
   DST=/var/www/html/segara
   mkdir -p "$DST"
   rsync -a --exclude='.git/' --exclude='tests/' --exclude='docs/' "$SRC/" "$DST/"
   mv "$DST/public" "$DST/public_html"
   mkdir -p "$DST/store/sessions" "$DST/store/logs" "$DST/store/backups" "$DST/uploads"
   ```

2. **Install dependencies** if `vendor/` was not copied:

   ```sh
   cd "$DST" && composer install --no-dev --optimize-autoloader
   ```

3. **Write `settings/config.php`** from `settings/config.example.php` and set the canonical origin. This value must match the HTTPS name visitors use:

   ```php
   'base_url' => 'https://segara.lan',
   'base_path' => '',
   'environment' => 'production',
   ```

4. **Create the store and the first administrator.**

   ```sh
   cd "$DST"
   php bin/migrate
   printf 'a-long-password\n' | php bin/setup --username=admin --display-name='Administrator'
   ```

5. **Hand `store/` and `uploads/` to the web user.** Do this after the migration: `bin/migrate` creates the SQLite file as whoever runs it, and a root-owned file makes the site fail with `attempt to write a readonly database`.

   ```sh
   chown -R www-data:www-data "$DST/store" "$DST/uploads"
   ```

6. **Get an HTTPS certificate.** Any certificate works as long as it covers the name in `base_url`. On a public hostname, `sudo certbot --nginx -d example.com` obtains one and edits the vhost for you; use `--webroot` instead if you run Apache. A LAN-only host still needs a certificate, either self-signed or from certbot with a DNS-01 challenge. Then set `ssl_certificate` and `ssl_certificate_key` in the vhost to point at the files.

7. **Configure the web server.** nginx: start from `deploy/nginx-php-fpm.conf`, point `root` at `public_html`, and include the `store/`, `uploads/`, and `settings/` deny rules in `deploy/security.md`. Apache: start from `deploy/apache-vhost.conf` with `mod_rewrite` and `mod_ssl` enabled. nginx ignores `.htaccess`, so the guards that `install.sh` installs only matter when Apache serves the app.

   ```sh
   ln -sf /etc/nginx/sites-available/segara /etc/nginx/sites-enabled/segara
   nginx -t && systemctl reload nginx
   ```

8. **Regenerate the public metadata files.**

   ```sh
   cd "$DST" && php bin/rebuild-public-files --public="$DST/public_html"
   ```

9. **Verify.**

   ```sh
   curl -sk https://segara.lan/                        # HTTP 200
   curl -sk https://segara.lan/admin.php?action=login  # HTTP 200
   curl -sk https://segara.lan/store/segara.sqlite     # HTTP 404
   ```

10. **Sign in** at `https://segara.lan/admin.php` with the account from step 4, then open Settings to set the site name, tagline, language, timezone, theme, and homepage. Reset a forgotten password from the shell with `php bin/password-reset --username=admin`.

Optional on a fresh install: `php bin/seed-blocks` adds a Quick Facts list, an RSS subscribe link, and a footer copyright line. It refuses to run once the store holds any block.

### Local development

```sh
composer install
cp settings/config.example.php settings/config.php   # base_url http://localhost:8080, environment development
php bin/migrate
php -S 127.0.0.1:8080 -t public public/router.php
```

Run the checks with `vendor/bin/phpunit`, `vendor/bin/phpunit --testsuite acceptance` (HTTP-level), `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/psalm --taint-analysis --no-progress`.

### Documentation

| Path | Covers |
|---|---|
| `docs/installation-tldr.md` | Copy-paste install on Ubuntu with nginx |
| `docs/deployment.md` | Server layout and web-server configuration |
| `docs/security.md` | Operator-side controls: canonical origin, headers, deny rules |
| `docs/content-format.md` | Page fields, Markdown subset, buttons, feeds |
| `docs/themes.md` | Theme contract, breakpoints, adding a theme |
| `docs/caching.md` | Cache keys, eligible responses, invalidation |
| `docs/restore.md` | Backup archives and the restore procedure |
| `deploy/nginx.md` | nginx walkthrough with a self-signed certificate |
| `deploy/first-admin.md` | Creating the first administrator |

## Licensing and credits

**Licence: BSD 2-Clause.** Copyright (c) 2026, Mohammad Hafiz bin Ismail (mypapit.net). Full text in [LICENSE](LICENSE). Use, modification, and redistribution are permitted in source and binary form, commercially or not, as long as the copyright notice and the warranty disclaimer travel with the source and appear in the documentation of anything shipped as a binary.

**Credits**

- Segara is written and maintained by **Mohammad Hafiz bin Ismail** (mypapit) — <https://github.com/mypapit/segara/>, blog at <https://blog.mypapit.net/>.
- Markdown rendering uses [Parsedown](https://github.com/erusev/parsedown) 1.8.0 by Emanuil Rusev (MIT).
- The `slate` theme loads Bootstrap 5.3.3 from jsDelivr, pinned by SRI and allowed through the CSP by path (MIT). No other third-party runtime asset is used: icons are an inline SVG sprite and the remaining styles are local.
- Development tooling: PHPUnit 13 (BSD-3-Clause), PHPStan 2 (MIT), Psalm 6 (MIT).

Bug reports, patches, and questions go to <https://github.com/mypapit/segara/issues>.
