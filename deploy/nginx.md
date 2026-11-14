# Deploy Segara on nginx — step by step

A complete recipe for a self-hosted, HTTPS, LAN-reachable Segara install.

## 0. Prerequisites

- Ubuntu (or any Linux) with `nginx` and `php8.4-fpm` installed and running.
- PHP 8.4.1+ with extensions: `dom fileinfo intl json mbstring pdo pdo_sqlite
  xml xmlreader xmlwriter zip`.
- `composer` (only needed if `vendor/` is not copied across).
- `openssl` for the self-signed certificate.

Check the essentials:

```sh
php -v
php -m | grep -E 'sqlite|mbstring|intl|dom|xml|zip'
nginx -v
systemctl is-active php8.4-fpm nginx
```

## 1. Deploy the application

### Option A — one command

```sh
sudo SEGARA_BASE_URL=https://segara.lan \
     SEGARA_ADMIN_PASSWORD='your-password-here' \
     ./deploy/install.sh
```

Defaults: target `/var/www/html/segara`, docroot `public_html`, web user
`www-data`. Override with `SEGARA_DST`, `SEGARA_DOCROOT`, `SEGARA_WEB_USER`.
The password must be 12+ characters.

### Option B — manual

```sh
SRC=/path/to/segara                    # the checkout
DST=/var/www/html/segara
sudo mkdir -p "$DST"
sudo rsync -a --exclude='.git/' --exclude='tests/' --exclude='docs/' "$SRC/" "$DST/"
sudo mv "$DST/public" "$DST/public_html"     # docroot rename
sudo mkdir -p "$DST/store/sessions" "$DST/store/logs" "$DST/store/backups" "$DST/uploads"
cd "$DST" && composer install --no-dev --optimize-autoloader    # if no vendor/
```

Write `settings/config.php` (copy `settings/config.example.php`), set the
canonical HTTPS origin and `'environment' => 'production'`.

Create the store and the first admin — see `first-admin.md`:

```sh
cd "$DST" && php bin/migrate
printf 'your-password-here\n' | php bin/setup --username=admin --display-name='Administrator'
```

Then, crucially, give the web server write access **after** the DB exists:

```sh
sudo chown -R www-data:www-data "$DST/store" "$DST/uploads"
```

Regenerate the public metadata:

```sh
cd "$DST" && php bin/rebuild-public-files --public="$DST/public_html"
```

## 2. Self-signed certificate

```sh
sudo mkdir -p /etc/ssl/segara
sudo openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
  -keyout /etc/ssl/segara/segara.lan.key \
  -out /etc/ssl/segara/segara.lan.crt \
  -subj "/C=US/ST=California/L=San Francisco/O=Segara/CN=segara.lan" \
  -addext "subjectAltName=DNS:segara.lan,IP:127.0.0.1,IP:192.0.2.10"
sudo chmod 600 /etc/ssl/segara/segara.lan.key
```

`192.0.2.10` stands in for this machine's LAN IP (`hostname -I`). Listing the
IP in the SAN means `https://192.0.2.10/` works with only the untrusted-certificate
warning and no hostname mismatch.

## 3. `/etc/hosts` virtual host

```sh
printf '127.0.0.1\tsegara.lan\n' | sudo tee -a /etc/hosts
```

## 4. nginx vhost

Copy `deploy/nginx-php-fpm.conf` to
`/etc/nginx/sites-available/segara`, then set:

- `server_name` → `segara.lan`
- `ssl_certificate` / `ssl_certificate_key` → the files from step 2
- `root` → `/var/www/html/segara/public_html` (your renamed docroot)
- `fastcgi_pass` → `/run/php/php8.4-fpm.sock` (confirm with
  `ls /run/php/`)

**Important:** if Apache (or anything else) already owns port 80 on this
machine, delete the `server { listen 80; ... }` block and serve HTTPS-only on
443. Check with `sudo ss -ltnp | grep -E ':(80|443)'`.

Enable and reload:

```sh
sudo ln -sf /etc/nginx/sites-available/segara /etc/nginx/sites-enabled/segara
sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` must print `syntax is ok` / `test is successful`. If a reload looks
successful but the site still won't answer, check
`/var/log/nginx/error.log` for a `bind() ... Address already in use` — that is
the port-80 conflict above; switch to HTTPS-only.

## 4b. Deny the data directories

The vhost above points `root` at `public_html/`, which already keeps `store/`, `uploads/` and
`settings/` off the web. Add the deny rules anyway: they cost nothing, and they are what saves you
if `root` is ever edited to the application directory by mistake. The full explanation, the Apache
equivalent and the rollback are in `deploy/security.md`.

Paste this inside the `server { listen 443 ssl; }` block, after the dotfile rule:

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

`sudo nginx -t && sudo systemctl reload nginx`, then check that a database path is refused:

## 5. Test

```sh
curl -sk https://segara.lan/                    # 200, title "Segara"
curl -sk https://segara.lan/admin.php?action=login   # 200
curl -sk https://segara.lan/rss.xml             # 200
curl -sk https://segara.lan/no-such-page        # 404
curl -sk -o /dev/null -w '%{http_code}\n' https://segara.lan/store/segara.sqlite   # 404
curl -sk -o /dev/null -w '%{http_code}\n' https://segara.lan/uploads/              # 404
```

## 6. From another machine on the LAN

- Add to the client's hosts file
  (Windows `C:\Windows\System32\drivers\etc\hosts`, macOS/Linux `/etc/hosts`):

  ```
  192.0.2.10    segara.lan
  ```

  then open `https://segara.lan/`.
- Or open `https://192.0.2.10/` directly and click through the self-signed
  warning.

## 7. Log in

`https://segara.lan/admin.php` — use the admin credentials from step 1. See
`first-admin.md` for the full admin walk-through.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Every page returns 500 | Old code: empty `Content-Length` | Use the fixed `src/Http/Request.php` (commit `747c255`); no config change needed |
| Login returns 500, site otherwise fine | DB owned by root, web server can't write | `sudo chown -R www-data:www-data /var/www/html/segara/store /var/www/html/segara/uploads` |
| `nginx -t` OK but no HTTPS | Port 80 conflict with Apache, reload silently kept old config | Serve HTTPS-only (drop the `listen 80` block); check `/var/log/nginx/error.log` |
| `curl https://segara.lan` → `000` | nginx not listening on 443 | `sudo ss -ltnp \| grep :443`; confirm the site is enabled and reloaded |
