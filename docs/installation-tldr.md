# Segara — quick install (TL;DR)

Run Segara on Ubuntu behind nginx over HTTPS, reachable from your LAN.

Throughout, `segara.lan` is the hostname and `192.0.2.10` is the server's LAN
address. Replace both with your own.

## 1. Prerequisites

- Ubuntu with `nginx` and `php8.4-fpm` installed.
- PHP 8.4 extensions: `dom fileinfo intl json mbstring pdo pdo_sqlite xml xmlreader xmlwriter zip`.
- `composer`, needed once to build `vendor/`.

Check with: `php -m | grep -E 'sqlite|mbstring|intl|dom|xml|zip'`

## 2. Copy the app into place

```sh
SRC=/path/to/segara          # the repo checkout
DST=/var/www/html/segara
mkdir -p "$DST"
rsync -a --exclude='.git/' --exclude='tests/' --exclude='docs/' "$SRC/" "$DST/"
mv "$DST/public" "$DST/public_html"     # docroot becomes public_html
mkdir -p "$DST/store/sessions" "$DST/store/logs" "$DST/store/backups" "$DST/uploads"
```

`store/`, `uploads/`, and `settings/` sit beside the docroot, never inside it.

## 3. Build dependencies (if you didn't copy vendor/)

```sh
cd "$DST" && composer install --no-dev --optimize-autoloader
```

## 4. Write settings/config.php

Copy `settings/config.example.php` to `settings/config.php` and set the
canonical origin to the HTTPS name you will use:

```php
'base_url' => 'https://segara.lan',
'base_path' => '',
'environment' => 'production',
```

## 5. Create the store and the first admin

```sh
cd "$DST"
php bin/migrate                              # OK: applied migrations 1
printf 'YOUR-PASSWORD\n' | php bin/setup --username=admin --display-name='Administrator'
                                             # OK: First administrator created.
```

## 6. Give the web server write access

Do this after the migration:

```sh
chown -R www-data:www-data "$DST/store" "$DST/uploads"
```

`bin/migrate` creates the SQLite file as whoever runs it. A file created by
`root` belongs to `root`, and the site then fails on login with
`attempt to write a readonly database`.

## 7. Get an HTTPS certificate

The certificate only has to cover the name in `base_url`. certbot obtains one
and records the renewal job for you:

```sh
sudo certbot --nginx -d segara.lan        # -d takes the name the certificate must cover
```

Use `--apache` when Apache serves the site, and `--webroot` when certbot should
write nothing to the vhost. A LAN-only name cannot answer an HTTP challenge:
either issue a self-signed certificate instead, or use certbot's DNS challenge
(`--preferred-challenges dns`, which needs a DNS plugin or a manual TXT record).

Note the paths certbot reports. They go into the vhost in step 9, typically
`/etc/letsencrypt/live/<name>/fullchain.pem` and `privkey.pem`.

## 8. Add the hostname to /etc/hosts

```sh
printf '127.0.0.1\tsegara.lan\n' | sudo tee -a /etc/hosts
```

## 9. Create the nginx site

Write `/etc/nginx/sites-available/segara`:

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name segara.lan;

    ssl_certificate     /etc/letsencrypt/live/segara.lan/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/segara.lan/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    root  /var/www/html/segara/public_html;
    index index.php;
    client_max_body_size 8m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\. { deny all; }
}
```

For a self-signed certificate, point `ssl_certificate` and
`ssl_certificate_key` at your own files instead.

`deploy/nginx-php-fpm.conf` is the fuller template: the same vhost plus 404
deny rules for `store/`, `uploads/`, and `settings/`, and a block that stops PHP
executing under a `documents/` or `videos/` directory.

Enable and reload:

```sh
sudo ln -sf /etc/nginx/sites-available/segara /etc/nginx/sites-enabled/segara
sudo nginx -t && sudo systemctl reload nginx
```

If another server already owns port 80 on that address, keep Segara HTTPS-only
on 443 as above and add no `listen 80` block.

## 10. Regenerate the public files

```sh
cd "$DST" && php bin/rebuild-public-files --public="$DST/public_html"
```

## 11. Test from the server

```sh
curl -sk https://segara.lan/                        # HTTP 200
curl -sk https://segara.lan/admin.php?action=login  # HTTP 200
curl -sk -o /dev/null -w '%{http_code}\n' \
  https://segara.lan/store/segara.sqlite            # 404
```

## 12. Reach the site from another machine

Add the server's LAN address to the client's hosts file (Windows
`C:\Windows\System32\drivers\etc\hosts`, macOS/Linux `/etc/hosts`):

```
192.0.2.10    segara.lan
```

Then browse `https://segara.lan/`. Browsing `https://192.0.2.10/` directly
works too, but the certificate must list that IP address or the browser reports
a name mismatch; a certbot certificate for a name will not.

## 13. Sign in

`https://segara.lan/admin.php` with username `admin` and the password from step
5. Reset it at any time:

```sh
php bin/password-reset --username=admin
```

## Rollback

- `/etc/hosts`: delete the line added in step 8.
- Site: `sudo rm /etc/nginx/sites-enabled/segara && sudo systemctl reload nginx`.
- Certificate: `sudo certbot delete --cert-name segara.lan` (certbot installs only).
- App: `rm -rf /var/www/html/segara`.
