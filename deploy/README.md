# Segara — deployment

Everything needed to take a fresh checkout and serve it behind nginx over
HTTPS, including the first admin account.

| File | Purpose |
|---|---|
| `install.sh` | One-command app deployment (copy, config, store, admin, permissions, public files) |
| `nginx-php-fpm.conf` | nginx + PHP-FPM vhost template |
| `apache-vhost.conf` | Apache vhost template (alternative to nginx) |
| `security.md` | Denying web access to `store/`, `uploads/` and `settings/` (nginx + Apache) |
| `apache-data-deny.htaccess` | App-root `.htaccess` guard, installed by `install.sh` (Apache, shared hosting) |
| `apache-dir-deny.htaccess` | `store/` and `uploads/` guard, installed by `install.sh` |
| `nginx.md` | Step-by-step guide: deploy Segara on nginx with HTTPS |
| `first-admin.md` | Create the first admin account and reach the admin UI |

## Quick start

```sh
sudo SEGARA_BASE_URL=https://segara.lan \
     SEGARA_ADMIN_PASSWORD='change-me-now-1234' \
     ./deploy/install.sh
```

Then follow `nginx.md` for the certificate, `/etc/hosts`, and the nginx vhost.

### Default blocks on a fresh install

A new store has no sidebar or footer content. The seeder fills that in:

```sh
sudo SEGARA_SEED=1 ./deploy/install.sh          # or, on an existing install:
php /var/www/html/segara/bin/seed-blocks
```

It creates a Quick Facts list and an RSS subscribe block in the left column, and
a copyright line in the footer. A menu block is added only when a menu already
exists, since a menu block pointing at nothing renders nothing. The seeder
refuses to run when the store already holds any block, so a re-deploy cannot
merge into a live site; `SEGARA_SEED` defaults to `0` for that reason. Rename the
sample facts and the `you@example.com` contact afterwards in Admin → Blocks.

## Four deployment problems, and what handles them now

Each one surfaced during a real install and is fixed in the tree.

1. **Empty `Content-Length`** — code bug, fixed. nginx and PHP-FPM set
   `CONTENT_LENGTH` to an empty string on GET requests without a body, and
   `Request::fromArrays()` treated that as malformed, so every page returned
   HTTP 500. `src/Http/Request.php` now reads an empty value as "no body", with
   regression coverage in `tests/RequestTest.php` (commit `747c255`).

2. **Read-only SQLite database** — operations footgun, scripted away.
   `bin/migrate` and `bin/setup` create the database file as whoever runs them.
   Run as root, the file stays root-owned and the web server fails its first
   write with `attempt to write a readonly database`. `install.sh` now chowns
   `store/` and `uploads/` to the web-server user as its last data step, after
   the file exists.

3. **Docroot rename** — handled. The canonical docroot is `public/`, while shared
   hosting usually expects `public_html/`. `install.sh` performs the rename, and
   `bin/check-environment --public=PATH` matches
   `bin/rebuild-public-files --public=PATH`, so both tools stay correct after it.

4. **Data one typo away from the web** — hardened. `store/` and `uploads/` sit
   outside the document root, so nothing leaks while `root` or `DocumentRoot`
   points at `public_html/`; that was verified 404. Nothing in the shipped
   configuration stopped a docroot pointed at the application directory from
   serving `store/segara.sqlite`, its `-wal`/`-shm` sidecars, session files and
   backups. Both vhost templates now carry the same deny pattern for the same
   three directory prefixes, `install.sh` writes root-owned `.htaccess` guards
   for Apache shared hosting, and `security.md` sets out the threat, the rules,
   and the proof.
