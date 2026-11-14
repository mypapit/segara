<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\MediaController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Media\ImageUpload;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class MediaDeletionTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private MediaRepository $media;
    private ImageUpload $upload;
    private PageRepository $pages;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private MediaController $controller;
    private string $adminId;
    private string $originalSessionName = '';
    private string $originalSavePath = '';

    /** @var array<string, string|false> */
    private array $originalIni = [];

    /** @var array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string} */
    private array $originalCookieParams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closeSession();
        foreach (['session.use_cookies', 'session.use_only_cookies', 'session.use_strict_mode', 'session.use_trans_sid'] as $key) {
            $this->originalIni[$key] = ini_get($key);
        }
        $this->originalCookieParams = session_get_cookie_params();
        $sessionName = session_name();
        $savePath = session_save_path();
        if ($sessionName === false || $savePath === false) {
            throw new RuntimeException('Unable to capture native session settings.');
        }
        $this->originalSessionName = $sessionName;
        $this->originalSavePath = $savePath;

        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->media = new MediaRepository($this->pdo);
        $this->upload = new ImageUpload($this->uploadsPath);
        $this->pages = new PageRepository($this->pdo);
        $this->admins = new AdminRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new MediaController(
            $this->config,
            $this->media,
            $this->admins,
            $this->session,
            $this->csrf,
            $this->upload,
        );
    }

    protected function tearDown(): void
    {
        $this->closeSession();
        foreach ($this->originalIni as $key => $value) {
            if ($value !== false) {
                ini_set($key, $value);
            }
        }
        $params = $this->originalCookieParams;
        $sameSite = $params['samesite'];
        if (in_array($sameSite, ['Lax', 'lax', 'None', 'none', 'Strict', 'strict'], true)) {
            session_set_cookie_params([
                'lifetime' => $params['lifetime'],
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $sameSite,
            ]);
        } else {
            session_set_cookie_params([
                'lifetime' => $params['lifetime'],
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
            ]);
            ini_set('session.cookie_samesite', $sameSite);
        }
        session_name($this->originalSessionName);
        session_save_path($this->originalSavePath);
        parent::tearDown();
    }

    public function testDeleteRefusesMediaReferencedByBodyMarkdown(): void
    {
        $media = $this->createMedia('a');
        $this->pages->create([
            'title' => 'Referencing Page',
            'state' => 'published',
            'author_name' => 'Site Administrator',
            'body_markdown' => '![image](/media.php?id=' . $media['id'] . ')',
        ]);

        try {
            $this->media->delete($media['id'], $this->uploadsPath);
            self::fail('Referenced media was deleted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('referenced', $e->getMessage());
        }
        self::assertNotNull($this->media->getById($media['id']));
        self::assertFileExists($this->uploadsPath . '/' . $media['stored_path']);
    }

    public function testDeleteRefusesMediaReferencedByFeaturedImage(): void
    {
        $media = $this->createMedia('b');
        $this->pages->create([
            'title' => 'Featured Page',
            'state' => 'published',
            'author_name' => 'Site Administrator',
        ]);
        $page = $this->pages->getBySlug('featured-page');
        self::assertNotNull($page);
        $this->pdo->prepare('UPDATE pages SET featured_media_id = :media WHERE id = :id')
            ->execute(['media' => $media['id'], 'id' => $page['id']]);

        try {
            $this->media->delete($media['id'], $this->uploadsPath);
            self::fail('Featured media was deleted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('referenced', $e->getMessage());
        }
        self::assertNotNull($this->media->getById($media['id']));
    }

    public function testDeleteRemovesUnreferencedMediaAndItsFile(): void
    {
        $media = $this->createMedia('c');
        $file = $this->uploadsPath . '/' . $media['stored_path'];
        self::assertFileExists($file);

        $this->media->delete($media['id'], $this->uploadsPath);

        self::assertNull($this->media->getById($media['id']));
        self::assertFileDoesNotExist($file);
    }

    public function testControllerDeleteRequiresPostAndValidCsrf(): void
    {
        $media = $this->createMedia('d');
        $this->authenticate();

        $get = $this->controller->delete($this->get());
        self::assertSame(405, $get->status());
        self::assertNotNull($this->media->getById($media['id']));

        $badCsrf = $this->controller->delete($this->post(['media_id' => $media['id'], '_csrf' => 'invalid']));
        self::assertSame(400, $badCsrf->status());
        self::assertNotNull($this->media->getById($media['id']));
    }

    public function testControllerDeleteRedirectsAnonymousAndRejectsInvalidId(): void
    {
        $anonymous = $this->controller->delete($this->post(['media_id' => str_repeat('e', 32)]));
        self::assertSame(302, $anonymous->status());

        $media = $this->createMedia('f');
        $this->authenticate();
        $invalid = $this->controller->delete($this->post(['media_id' => 'not-an-id', '_csrf' => $this->csrf->token()]));
        self::assertSame(422, $invalid->status());
        self::assertNotNull($this->media->getById($media['id']));
    }

    public function testDeleteUnknownMediaIsRejected(): void
    {
        try {
            $this->media->delete(str_repeat('9', 32), $this->uploadsPath);
            self::fail('Unknown media was accepted for deletion.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    /** @return array{id: string, stored_path: string} */
    private function createMedia(string $prefix): array
    {
        $storedPath = str_repeat($prefix, 32) . '.png';
        file_put_contents($this->uploadsPath . '/' . $storedPath, 'x');
        $media = $this->media->create([
            'original_name' => $prefix . '.png',
            'stored_path' => $storedPath,
            'mime' => 'image/png',
            'size' => 1,
            'alt_text' => 'An image',
            'decorative' => 0,
        ]);

        return ['id' => $media['id'], 'stored_path' => $media['stored_path']];
    }

    private function authenticate(): void
    {
        $admin = $this->admins->findById($this->adminId);
        self::assertNotNull($admin);
        $this->session->login($admin['id'], $admin['auth_epoch']);
    }

    /** @param array<string, mixed> $post */
    private function post(array $post): Request
    {
        return Request::fromArrays(['REQUEST_METHOD' => 'POST'], $post);
    }

    private function get(): Request
    {
        return Request::fromArrays(['REQUEST_METHOD' => 'GET']);
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $_SESSION = [];
        $_COOKIE = [];
        session_id('');
    }
}
