# Denying web access to the database and the data directories

## What is at risk

`deploy/install.sh` keeps the data outside the document root:

```
/var/www/html/segara/
├── public_html/        <- DocumentRoot (formerly public/)
├── store/
│   ├── segara.sqlite   pages, admins and their password hashes, settings,
│   │                   media rows, contact submissions
│   ├── segara.sqlite-wal / -shm / -journal   unmerged writes
│   ├── sessions/       PHP session files; one of them is a login
│   ├── logs/           request and error logs
│   └── backups/        archives written by bin/backup
├── uploads/            original images; media.php streams them by id
└── settings/config.php paths, environment, base URL
```

With `DocumentRoot` (Apache) or `root` (nginx) on `public_html/`, each of those
paths returns 404. Measured on a live install:

```
/store/segara.sqlite      404
/store/backups            404
/uploads/                 404
/settings/config.php      404
```

The exposure starts when that one setting points at the application directory
instead. Shared-hosting panels often pin it there, and the docroot is the first
thing an operator edits when a site starts returning 403. From then on
`https://host/store/segara.sqlite` downloads the database, unauthenticated, with
no code change on either side.

## The rules

All three layers use one pattern:

```
\.(?:sqlite3?|db3?)(?:-(?:wal|shm|journal))?$
```

It matches `segara.sqlite`, `x.sqlite3`, `dump.db`, `dump.db3`,
`segara.sqlite-wal`, `segara.sqlite-shm`, `segara.sqlite-journal` and
`dump.db-journal`. It does not match `index.php`, `admin.css`, `rss.xml` or
`uploads-notice.md`.

### nginx — `deploy/nginx-php-fpm.conf`

Paste inside the `server { listen 443 ssl; }` block:

```nginx
location ~* \.(?:sqlite3?|db3?)(?:-(?:wal|shm|journal))?$ {
    return 404;
}

location ^~ /store/ {
    return 404;
}

location ^~ /uploads/ {
    return 404;
}

location ^~ /settings/ {
    return 404;
}
```

### Apache — `deploy/apache-vhost.conf`

Paste inside `<VirtualHost *:443>`:

```apache
<FilesMatch "(?i)\.(?:sqlite3?|db3?)(?:-(?:wal|shm|journal))?$">
    Require all denied
</FilesMatch>

<DirectoryMatch "^/var/www/segara/(store|uploads|settings)(/|$)">
    Options -Indexes
    Require all denied
</DirectoryMatch>
```

`DirectoryMatch` is matched against the filesystem path, not the URL, so change
`/var/www/segara` if the application lives elsewhere.

### Apache shared hosting, no vhost — `.htaccess`

`install.sh` writes `deploy/apache-data-deny.htaccess` to `<target>/.htaccess`,
and `deploy/apache-dir-deny.htaccess` to `store/.htaccess` and
`uploads/.htaccess`. They need `AllowOverride All`. `uploads/.htaccess` sits in
a directory the web server can write, so the root-level file is the strong guard
and the directory copy is convenience. `SEGARA_APACHE_GUARDS=0` skips all three.

## Why 404 rather than 403

A 403 confirms a file exists at that path. A 404 says nothing, so nginx is told
to pretend the data was never there. Apache returns 403 because
`Require all denied` is how that intent is expressed, and against a directory
that should not be reachable at all the difference in what it discloses is
negligible.

## Verify

On a correct install all three return 404:

```sh
curl -sk -o /dev/null -w '%{http_code}\n' https://segara.lan/store/segara.sqlite
curl -sk -o /dev/null -w '%{http_code}\n' https://segara.lan/store/backups
curl -sk -o /dev/null -w '%{http_code}\n' https://segara.lan/uploads/
```

That proves the docroot, not the rules. To exercise the rules themselves, serve
a directory that contains the data and request the database:

```sh
# nginx: a scratch listener on 127.0.0.1:8099 with root at a temp dir holding
# store/segara.sqlite returns 200 without the location block and 404 with it.
# Apache: the same test on 127.0.0.1:8098 returns 200 without the sections and
# 403 with them, using a private httpd.conf so the system vhost is untouched.
```

Both were run against the snippets as shipped. `tests/DeployHardeningTest.php`
re-runs the patterns against real filenames on every test run, so a typo fails
the suite instead of reaching a server.

## What this does not cover

- `vendor/`, `src/`, `composer.json` and `migrations/` stay readable when the
  nginx docroot is wrong. These rules protect the data, not the source tree.
  Fix the docroot.
- `.git/`: the nginx template denies dotfiles, and `install.sh` excludes `.git/`
  from the copy.
- A local user who can read the filesystem. This is a web-server exposure
  control only.

## Rollback

Delete the four `location` blocks from `deploy/nginx-php-fpm.conf`, the two
sections from `deploy/apache-vhost.conf`, and the `.htaccess` files from the
target. No part of the application depends on them.
