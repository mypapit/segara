<?php

declare(strict_types=1);

namespace Segara\Maintenance;

use InvalidArgumentException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Create and restore consistent backups of the SQLite database and uploads.
 *
 * A backup is a ZIP under store/backups/ containing a VACUUM INTO snapshot of
 * the database, every upload file, and a manifest with a format version and a
 * SHA-256 per file. Restore validates the manifest, every checksum, and every
 * entry path before extracting into an empty target directory only.
 *
 * @phpstan-type BackupManifest array{format: string, version: int, created_at: string, files: array<string, string>}
 */
final class Backup
{
    public function __construct(private readonly string $storeDir)
    {
    }

    /**
     * @return array{zip: string, files: list<string>, bytes: int}
     */
    public function create(PDO $pdo, string $uploadsDir): array
    {
        $backupsDir = rtrim($this->storeDir, '/') . '/backups';
        if (!is_dir($backupsDir)) {
            @mkdir($backupsDir, 0770, true);
        }
        $zipPath = $backupsDir . '/backup-' . gmdate('Ymd-His') . '.zip';

        $work = sys_get_temp_dir() . '/segara-backup-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0770, true) && !is_dir($work)) {
            throw new RuntimeException('Backup staging directory could not be created.');
        }
        try {
            $snapshot = $work . '/database.sqlite';
            $this->snapshotDatabase($pdo, $snapshot);
            $files = ['database.sqlite' => $snapshot];
            foreach ($this->uploadFiles($uploadsDir) as $rel => $abs) {
                $files['uploads/' . $rel] = $abs;
            }
            $this->writeZip($zipPath, $files, $this->manifest($files));
        } finally {
            $this->removeTree($work);
        }

        $bytes = filesize($zipPath);
        if ($bytes === false) {
            throw new RuntimeException('Backup archive size could not be read.');
        }

        return ['zip' => $zipPath, 'files' => array_keys($files), 'bytes' => $bytes];
    }

    public function restore(string $zipPath, string $targetDir): void
    {
        if (!is_file($zipPath)) {
            throw new InvalidArgumentException('Backup archive not found.');
        }
        if (is_dir($targetDir) && $this->hasEntries($targetDir)) {
            throw new InvalidArgumentException('Restore target must be empty.');
        }
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0770, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Backup archive is not readable.');
        }
        try {
            $manifest = $this->readManifest($zip);
            $count = $zip->numFiles;
            for ($i = 0; $i < $count; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $this->assertSafePath($name);
                if ($name === 'manifest.json') {
                    continue;
                }
                $expected = $manifest['files'][$name] ?? null;
                if (!is_string($expected)) {
                    throw new InvalidArgumentException('Backup contains an unlisted entry: ' . $name);
                }
                if ($this->hashEntry($zip, $name) !== $expected) {
                    throw new InvalidArgumentException('Backup checksum mismatch: ' . $name);
                }
            }
            if (!$zip->extractTo($targetDir)) {
                throw new RuntimeException('Backup extraction failed.');
            }
        } finally {
            $zip->close();
        }
    }

    private function snapshotDatabase(PDO $pdo, string $target): void
    {
        $escaped = str_replace("'", "''", $target);
        $pdo->exec("VACUUM INTO '" . $escaped . "'");
        if (!is_file($target)) {
            throw new RuntimeException('Database snapshot could not be created.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function uploadFiles(string $uploadsDir): array
    {
        $files = [];
        if (!is_dir($uploadsDir)) {
            return $files;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $rel = substr($path, strlen(rtrim($uploadsDir, '/')) + 1);
            $files[$rel] = $path;
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @return BackupManifest
     */
    private function manifest(array $files): array
    {
        $entries = [];
        foreach ($files as $rel => $abs) {
            $hash = hash_file('sha256', $abs);
            if ($hash === false) {
                throw new RuntimeException('Backup hash failed for ' . $rel . '.');
            }
            $entries[$rel] = $hash;
        }

        return [
            'format' => 'segara-backup',
            'version' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'files' => $entries,
        ];
    }

    /**
     * @param array<string, string> $files
     * @param BackupManifest $manifest
     */
    private function writeZip(string $zipPath, array $files, array $manifest): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Backup archive could not be created.');
        }
        try {
            foreach ($files as $rel => $abs) {
                if (!$zip->addFile($abs, $rel)) {
                    throw new RuntimeException('Backup archive could not include ' . $rel . '.');
                }
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Backup manifest could not be encoded.');
            }
            $zip->addFromString('manifest.json', $json);
        } finally {
            $zip->close();
        }
    }

    /** @return BackupManifest */
    private function readManifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');
        if ($raw === false) {
            throw new InvalidArgumentException('Backup is missing its manifest.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)
            || ($manifest['format'] ?? null) !== 'segara-backup'
            || ($manifest['version'] ?? null) !== 1
            || !is_array($manifest['files'] ?? null)
        ) {
            throw new InvalidArgumentException('Backup manifest is invalid.');
        }
        /** @var BackupManifest $manifest */
        return $manifest;
    }

    private function hashEntry(ZipArchive $zip, string $name): string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new InvalidArgumentException('Backup entry unreadable: ' . $name);
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_final($hash);
    }

    private function assertSafePath(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
            || preg_match('/(^|\/)\.\.(\/|$)/', $name) === 1
        ) {
            throw new InvalidArgumentException('Backup contains an unsafe entry path: ' . $name);
        }
    }

    private function hasEntries(string $dir): bool
    {
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }

        return false;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
