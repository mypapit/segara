# Security (operator guide)

Segara treats the web as untrusted and the source as public. This guide covers
the operator-side controls: origin, transport, admin access, sessions and CSRF,
uploads, secrets and logs, recovery, and backups.

## Canonical origin

`settings/config.php` declares a single canonical `base_url` (the HTTPS origin).
All absolute links, feeds, sitemaps, and metadata derive from it — never from
the request `Host` header. Change it only in `config.php`; it is not editable
from the admin UI.

## Transport

- Production administration requires HTTPS.
- The web-server templates in `docs/deployment.md` redirect HTTP to HTTPS.
- Security headers (CSP, `nosniff`, `Referrer-Policy`, HSTS, frame-ancestors)
  are emitted by the application via the `SecurityHeaders` layer.

## Administration access

Three layers, in order:

1. **Optional external Basic Auth** — an `htpasswd` file stored *outside* the
   document root, layered in front of the application login (see the commented
   blocks in `deploy/*`). Create it with `htpasswd -c /etc/apache2/segara-htpasswd admin`.
2. **Application login** — username/password against the admin table, with
   bounded login throttling and a generic failure message.
3. **CAPTCHA** — when `captchaProvider` is set in `config.php`, the login form
   requires a CAPTCHA challenge.

## Sessions and CSRF

Sessions use `Secure`, `HttpOnly`, `SameSite=Lax` cookies with strict mode and
absolute idle/absolute timeouts. Every state-changing request requires a CSRF
token; GET never mutates state.

## Uploads and media

- Only JPEG, PNG, and WebP are accepted, verified by `finfo`, capped at 5 MiB.
- Uploaded files get a random filename and are stored in `uploads/`, outside the
  document root, served only through `media.php` with validated IDs.
- Video and documents are **not uploaded**; they are separately deployed local
  paths referenced by the page, served non-executable by the web server.

## Secrets and logs

- All credentials live in `settings/config.php` (git-ignored); nothing is
  committed.
- Error logs live in `store/logs/`, rotate at 1 MiB (five files), and redact
  passwords, tokens, cookies, authorization headers, and session IDs.
- Production disables `display_errors` and returns a generic 500 page with a
  random reference ID.

## Reset and recovery

`php bin/password-reset --username=NAME` resets an administrator password from
the command line (reads the password from stdin without echoing). There is no
web-based recovery flow.

## Backups

Backups are written to `store/backups/` — outside the document root and never
web-accessible. Restore validates the manifest, every checksum, and every path
before extraction, and refuses to overwrite a non-empty target.

## Intentionally absent

There is no MySQL, no outgoing mail, no plugin system, no public installer, no
pagination, and no web-based password recovery. Do not introduce these; they
are outside Segara's threat model.
