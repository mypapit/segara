# First admin account and the admin UI

## Create the first admin

Run this on the server, before the site is reachable:

```sh
cd /var/www/html/segara
php bin/setup --username=admin --display-name='Administrator'
```

`bin/setup` prompts for the password with echo off, applies any pending
migrations, and refuses a second run. A repeat attempt prints:

```
Administrator setup has already been completed; refusing to overwrite it.
```

For scripting, pipe the password in:

```sh
printf 'your-password-here\n' | php bin/setup --username=admin --display-name='Administrator'
# OK: First administrator created.
```

The hidden prompt keeps the password out of `ps` output and your shell history.
A `printf` line leaves it in your shell history, so clear the entry afterwards
or use the prompt.

Limits:

- Username and display name: 1–200 characters.
- Password: 12–4096 bytes, no null bytes.
- The stored value is a `password_hash()` digest using `PASSWORD_DEFAULT`. No
  plaintext copy is written or logged.

## Reach the admin UI

1. Open `https://segara.lan/admin.php`. A request without a session lands on the
   login form.
2. Submit the username and password. The form carries a CSRF token, repeated
   failures are throttled, and logout ends the session.
3. The dashboard opens.

Every admin screen is `admin.php?action=<name>`:

| Action | Screen |
|---|---|
| none | Dashboard |
| `accounts` | Administrators: create, change password, delete |
| `pages` | Page list, create, edit, trash, restore, delete |
| `media` | Upload and delete images |
| `menu` | Menus, items, ordering, which menu the header shows |
| `blocks` | Sidebar and footer blocks |
| `settings` | Site identity, theme, language and regional display, homepage |
| `messages` | Contact form inbox |
| `transfer` | WXR import and export |
| `logout` | End the session |

## Reset a forgotten password

No login needed, but shell access on the server:

```sh
cd /var/www/html/segara
printf 'new-password-here\n' | php bin/password-reset --username=admin
```

The reset increments that administrator's `auth_epoch`, and every admin request
compares the session's copy against it. Cookies issued under the old password
stop authenticating at the next request.

## After a fresh deploy

1. Open Settings and check the canonical `base_url`. It is read-only in the
   panel, so a wrong value gets fixed in `settings/config.php`.
2. Publish the first page, then run
   `php bin/rebuild-public-files --public=/var/www/html/segara/public_html` so
   `sitemap.xml`, `robots.txt`, and `llms.txt` list it.
3. If the site stays LAN-only, an `htpasswd` layer in front of `admin.php` adds
   a gate before the Segara login. The commented block in
   `deploy/nginx-php-fpm.conf` shows the configuration.
