# Backup and restore

Backups are consistent snapshots of the SQLite database (via `VACUUM INTO`)
plus every file under `uploads/`, wrapped in a timestamped ZIP under
`store/backups/`. Each archive carries a `manifest.json` with a format version
and a SHA-256 checksum per entry. Backups live outside `public/` and are never
web-accessible.

## Create a backup

```sh
php bin/backup
# OK: backup written to store/backups/backup-20261025-071500.zip (N bytes, M files)
```

## Restore a backup

Restore only into an **empty** target directory and never touches the live
`store/` by default. The `--confirm` flag is mandatory.

```sh
php bin/restore --file=store/backups/backup-20261025-071500.zip \
    --target=/tmp/segara-restored --confirm
# OK: restored to /tmp/segara-restored
```

The restored tree contains `database.sqlite`, `uploads/`, and `manifest.json`.
Open the restored database at `restored/database.sqlite` to verify content and
administrator authentication before any manual promotion.

## Integrity checks

On restore, every entry is verified in order:

1. `manifest.json` must exist with `format=segara-backup` and `version=1`.
2. Every non-manifest entry must be listed in the manifest.
3. Every entry's SHA-256 must match the manifest.
4. Every entry path must be safe (no absolute paths, no `..` traversal).

Any failure aborts before extraction — nothing is written to the target.

## Rolling back a live site

1. Create a fresh backup of the current state (`php bin/backup`).
2. Restore the older archive into an empty directory.
3. Verify pages, media, settings, and admin login against the restored copy.
4. Stop the web server, swap `store/segara.sqlite` and `uploads/` from the
   restored copy, then start the server.
