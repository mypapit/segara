#!/usr/bin/env bash
set -euo pipefail

# Segara — one-command app deployment (nginx HTTPS layout).
#
# Deploys the source tree into a document-root layout, writes
# settings/config.php, creates the store and the first administrator, fixes
# web-server ownership, and regenerates sitemap/robots/llms.
#
# Certificate + nginx vhost + /etc/hosts are separate — see deploy/nginx.md.
#
# Usage:
#   sudo SEGARA_BASE_URL=https://segara.lan \
#        SEGARA_ADMIN_PASSWORD='your-password' \
#        ./deploy/install.sh
#
# Optional environment overrides:
#   SEGARA_SRC            source tree (default: this repository)
#   SEGARA_DST            install target (default: /var/www/html/segara)
#   SEGARA_DOCROOT        document-root name inside the target (default: public_html)
#   SEGARA_WEB_USER       web-server user (default: www-data)
#   SEGARA_WEB_GROUP      web-server group (default: same as SEGARA_WEB_USER)
#   SEGARA_BASE_URL       canonical HTTPS origin (default: https://segara.lan)
#   SEGARA_ADMIN_USER     first admin username (default: admin)
#   SEGARA_ADMIN_PASSWORD first admin password (prompted if unset)
#   SEGARA_ADMIN_SKIP=1   migrate only, do not create the admin
#   SEGARA_APACHE_GUARDS  write the Apache .htaccess data guards (default: 1)

SRC="${SEGARA_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
DST="${SEGARA_DST:-/var/www/html/segara}"
DOCROOT="${SEGARA_DOCROOT:-public_html}"
WEB_USER="${SEGARA_WEB_USER:-www-data}"
WEB_GROUP="${SEGARA_WEB_GROUP:-$WEB_USER}"
BASE_URL="${SEGARA_BASE_URL:-https://segara.lan}"
ADMIN_USER="${SEGARA_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${SEGARA_ADMIN_PASSWORD:-}"

if [[ $EUID -ne 0 ]]; then
    echo "ERROR: run as root (sudo ./deploy/install.sh) so ownership and $DST can be set." >&2
    exit 1
fi

echo "==> Deploying Segara"
echo "    source:    $SRC"
echo "    target:    $DST"
echo "    docroot:   $DOCROOT"
echo "    web user:  $WEB_USER:$WEB_GROUP"
echo "    base_url:  $BASE_URL"

# 1. Copy the tree (skip dev-only artifacts).
mkdir -p "$DST"
rsync -a \
    --exclude='.git/' --exclude='.hermes/' --exclude='.phpunit.cache/' --exclude='.phpstan.cache/' --exclude='.psalm/' \
    --exclude='tests/' --exclude='docs/' --exclude='deploy/' \
    --exclude='*.md' --exclude='.gitignore' \
    --exclude='phpstan.neon' --exclude='psalm.xml' --exclude='phpunit.xml' \
    --exclude='.phpunit.result.cache' \
    --exclude='code.html' --exclude='screen.png' \
    "$SRC/" "$DST/"

# 2. Document root: the canonical source dir is public/.
if [[ -d "$DST/public" ]]; then
    rm -rf "$DST/$DOCROOT"
    mv "$DST/public" "$DST/$DOCROOT"
fi

# 3. Runtime data directories.
mkdir -p "$DST/store/sessions" "$DST/store/logs" "$DST/store/backups" "$DST/uploads"

# 4. Dependencies (only when vendor/ was not copied across).
if [[ ! -f "$DST/vendor/autoload.php" ]]; then
    (cd "$DST" && composer install --no-dev --optimize-autoloader)
fi

# 5. settings/config.php (preserved on re-runs).
if [[ ! -f "$DST/settings/config.php" ]]; then
    cat > "$DST/settings/config.php" <<PHPEOF
<?php

declare(strict_types=1);

return [
    'base_url' => '$BASE_URL',
    'base_path' => '',
    'environment' => 'production',
    'session' => [
        'idle_timeout' => 1800,
        'absolute_timeout' => 28800,
    ],
    'cache' => [
        'enabled' => false,
    ],
    'captcha' => [
        'provider' => null,
        'site_key' => null,
        'secret_key' => null,
    ],
    'auth' => [
        'basic_realm' => 'Segara Administration',
    ],
];
PHPEOF
fi

# 6. Early environment gate (PHP version, extensions, layout).
(cd "$DST" && php bin/check-environment --public="$DST/$DOCROOT")

# 7. Store schema + first admin. bin/setup migrates, then creates the admin.
if [[ -f "$DST/store/segara.sqlite" ]]; then
    echo "==> Store already initialized; skipping admin setup (delete $DST/store/segara.sqlite for a fresh install)."
elif [[ -n "$ADMIN_PASSWORD" ]]; then
    printf '%s\n' "$ADMIN_PASSWORD" | (cd "$DST" && php bin/setup --username="$ADMIN_USER" --display-name='Administrator')
elif [[ "${SEGARA_ADMIN_SKIP:-0}" == "1" ]]; then
    (cd "$DST" && php bin/migrate)
    echo "==> Admin creation skipped. Run later: php $DST/bin/setup --username=$ADMIN_USER"
else
    read -rsp "    password for '$ADMIN_USER' (hidden, 12+ chars): " ADMIN_PASSWORD; echo
    printf '%s\n' "$ADMIN_PASSWORD" | (cd "$DST" && php bin/setup --username="$ADMIN_USER" --display-name='Administrator')
fi

# 7b. Optional default blocks. Off by default so a re-deploy never touches a
#     live store; the seeder also refuses when any block already exists.
if [[ "${SEGARA_SEED:-0}" == "1" ]]; then
    if ! (cd "$DST" && php bin/seed-blocks); then
        echo "ERROR: seed-blocks failed. The store is unchanged (the seeder rolls back);" >&2
        echo "       fix the cause and re-run, or set SEGARA_SEED=0." >&2
        exit 1
    fi
fi

# 8. Web-server write access (must happen AFTER the DB file is created).
chown -R "$WEB_USER:$WEB_GROUP" "$DST/store" "$DST/uploads"

# 8b. Apache fallback guards. nginx never reads .htaccess, so these are inert there;
#     they exist for Apache with a wrong DocumentRoot, where no vhost edit is
#     available. Written after the chown so they stay root-owned: the web server
#     owns uploads/ and must not be able to rewrite the rule that guards it.
if [[ "${SEGARA_APACHE_GUARDS:-1}" == "1" ]]; then
    install -m 0644 "$SRC/deploy/apache-data-deny.htaccess" "$DST/.htaccess"
    install -m 0644 "$SRC/deploy/apache-dir-deny.htaccess" "$DST/store/.htaccess"
    install -m 0644 "$SRC/deploy/apache-dir-deny.htaccess" "$DST/uploads/.htaccess"
fi

# 9. Public metadata (sitemap.xml, robots.txt, llms.txt).
(cd "$DST" && php bin/rebuild-public-files --public="$DST/$DOCROOT")

echo "==> App deployed to $DST (docroot: $DST/$DOCROOT)."
echo "    Next: certificate + nginx vhost — follow deploy/nginx.md"
