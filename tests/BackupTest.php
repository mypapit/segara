<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use Segara\Auth\AdminRepository;
use Segara\Contact\MessageRepository;
use Segara\Maintenance\Backup;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;
use ZipArchive;

final class BackupTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private string $root;
    private string $storeDir;
    private string $uploadsDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/segara-backup-test-' . bin2hex(random_bytes(8));
        $this->storeDir = $this->root . '/store';
        $this->uploadsDir = $this->root . '/uploads';
        mkdir($this->storeDir, 0777, true);
        mkdir($this->uploadsDir, 0777, true);
        $this->pdo = Database::connect($this->storeDir . '/segara.sqlite');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testBackupCreatesZipWithManifestAndHashes(): void
    {
        $this->seed($this->pdo, $this->uploadsDir);

        $result = (new Backup($this->storeDir))->create($this->pdo, $this->uploadsDir);
        self::assertFileExists($result['zip']);
        self::assertContains('database.sqlite', $result['files']);
        self::assertGreaterThan(0, $result['bytes']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['zip']));
        $manifest = $this->readManifest($zip);
        self::assertArrayHasKey('database.sqlite', $manifest['files']);
        foreach ($manifest['files'] as $name => $hash) {
            $bytes = $zip->getFromName($name);
            self::assertNotFalse($bytes);
            self::assertSame($hash, hash('sha256', $bytes));
        }
        $zip->close();
    }

    public function testRestoreRecreatesContentAndAuthentication(): void
    {
        $fixture = $this->seed($this->pdo, $this->uploadsDir);
        $backup = new Backup($this->storeDir);
        $result = $backup->create($this->pdo, $this->uploadsDir);

        $target = $this->root . '/restored';
        $backup->restore($result['zip'], $target);

        $restored = Database::connect($target . '/database.sqlite');
        self::assertNotNull((new PageRepository($restored))->getBySlug($fixture['slug']));

        $admin = (new AdminRepository($restored))->findById($fixture['admin_id']);
        self::assertNotNull($admin);
        self::assertTrue(password_verify(self::PASSWORD, $admin['password_hash']));

        self::assertSame($fixture['site_name'], (new SettingsRepository($restored))->get('site_name'));
        self::assertCount(1, (new MessageRepository($restored))->list());
        self::assertFileExists($target . '/uploads/' . $fixture['stored_path']);
    }

    public function testRestoreRequiresEmptyTarget(): void
    {
        $this->seed($this->pdo, $this->uploadsDir);
        $result = (new Backup($this->storeDir))->create($this->pdo, $this->uploadsDir);

        $target = $this->root . '/occupied';
        mkdir($target, 0777, true);
        file_put_contents($target . '/existing.txt', 'x');

        $this->expectException(InvalidArgumentException::class);
        (new Backup($this->storeDir))->restore($result['zip'], $target);
    }

    public function testRestoreRejectsMissingManifest(): void
    {
        $zipPath = $this->root . '/no-manifest.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sqlite', 'x');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        (new Backup($this->storeDir))->restore($zipPath, $this->root . '/target');
    }

    public function testRestoreRejectsUnsafeEntryPath(): void
    {
        $zipPath = $this->root . '/unsafe.zip';
        $json = json_encode([
            'format' => 'segara-backup', 'version' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'files' => ['../evil.php' => 'abc'],
        ]);
        self::assertNotFalse($json);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $json);
        $zip->addFromString('../evil.php', 'x');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        (new Backup($this->storeDir))->restore($zipPath, $this->root . '/target-unsafe');
    }

    public function testRestoreRejectsChecksumMismatch(): void
    {
        $zipPath = $this->root . '/tampered.zip';
        $json = json_encode([
            'format' => 'segara-backup', 'version' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'files' => ['database.sqlite' => str_repeat('0', 64)],
        ]);
        self::assertNotFalse($json);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $json);
        $zip->addFromString('database.sqlite', 'actual-bytes');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        (new Backup($this->storeDir))->restore($zipPath, $this->root . '/target-tampered');
    }

    /** @return array<string, string> */
    private function seed(PDO $pdo, string $uploadsDir): array
    {
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $admin = (new AdminRepository($pdo))->create('admin', 'Site Administrator', self::PASSWORD);

        (new PageRepository($pdo))->create([
            'title' => 'Hello Backup', 'slug' => 'hello-backup', 'state' => 'published',
            'published_at' => '2026-10-25T00:00:00Z', 'body_markdown' => 'Backup **body**', 'tags' => ['one'],
        ]);

        $storedPath = str_repeat('b', 32) . '.jpg';
        file_put_contents($uploadsDir . '/' . $storedPath, 'fake-image-bytes');
        (new MediaRepository($pdo))->create([
            'original_name' => 'cover.jpg', 'stored_path' => $storedPath,
            'mime' => 'image/jpeg', 'size' => 16, 'alt_text' => 'Cover', 'decorative' => 0,
        ]);

        (new MessageRepository($pdo))->create(['name' => 'A', 'email' => 'a@b.com', 'subject' => 'Hi', 'body' => 'B']);
        (new SettingsRepository($pdo))->setMany(['site_name' => 'Backup Site']);

        return [
            'admin_id' => $admin['id'],
            'slug' => 'hello-backup',
            'stored_path' => $storedPath,
            'site_name' => 'Backup Site',
        ];
    }

    /**
     * @return array{format: string, version: int, files: array<string, string>}
     */
    private function readManifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');
        self::assertNotFalse($raw);
        $manifest = json_decode($raw, true);
        self::assertIsArray($manifest);
        self::assertSame('segara-backup', $manifest['format'] ?? null);
        self::assertSame(1, $manifest['version'] ?? null);
        self::assertIsArray($manifest['files'] ?? null);
        $files = [];
        foreach ($manifest['files'] as $name => $hash) {
            if (is_string($name) && is_string($hash)) {
                $files[$name] = $hash;
            }
        }

        return ['format' => 'segara-backup', 'version' => 1, 'files' => $files];
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
