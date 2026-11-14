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
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class MediaTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private MediaRepository $media;
    private ImageUpload $upload;
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

    public function testValidateAcceptsJpegPngAndWebpFixtures(): void
    {
        $jpeg = $this->jpegFixture();
        $png = $this->pngFixture();
        $webp = $this->webpFixture();

        self::assertSame('image/jpeg', $this->upload->validate($jpeg, 'a.jpg', (int) filesize($jpeg))['mime']);
        self::assertSame('image/png', $this->upload->validate($png, 'a.png', (int) filesize($png))['mime']);
        self::assertSame('image/webp', $this->upload->validate($webp, 'a.webp', (int) filesize($webp))['mime']);
    }

    public function testValidateRejectsMimeMismatch(): void
    {
        $text = $this->temporaryRoot . '/fake.png';
        file_put_contents($text, 'plain text that is not an image');

        try {
            $this->upload->validate($text, 'fake.png', (int) filesize($text));
            self::fail('A file with a non-image MIME type was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('supported', $e->getMessage());
        }
    }

    public function testValidateRejectsTraversalFilenameAndSymlinkAndOversize(): void
    {
        $png = $this->pngFixture();

        try {
            $this->upload->validate($png, '../evil.png', (int) filesize($png));
            self::fail('Traversal filename was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('filename', $e->getMessage());
        }

        $link = $this->temporaryRoot . '/link.png';
        symlink($png, $link);
        try {
            $this->upload->validate($link, 'link.png', (int) filesize($png));
            self::fail('Symlink was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('could not be read', $e->getMessage());
        }

        try {
            $this->upload->validate($png, 'big.png', 5242881);
            self::fail('Oversized image was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('5 MiB', $e->getMessage());
        }
    }

    public function testStoreRejectsNonUploadedFile(): void
    {
        $png = $this->pngFixture();
        $file = [
            'name' => 'a.png',
            'tmp_name' => $png,
            'size' => (int) filesize($png),
            'error' => UPLOAD_ERR_OK,
        ];

        try {
            $this->upload->store($file);
            self::fail('A non-uploaded file was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('could not be verified', $e->getMessage());
        }
    }

    public function testRepositoryCreateRequiresAltTextUnlessDecorativeAndValidatesFields(): void
    {
        $valid = [
            'original_name' => 'hero.jpg',
            'stored_path' => str_repeat('a', 32) . '.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'alt_text' => 'A hero image',
            'decorative' => 0,
        ];
        $created = $this->media->create($valid);
        self::assertSame('hero.jpg', $created['original_name']);
        self::assertSame('A hero image', $created['alt_text']);

        $decorative = $this->media->create(array_merge($valid, [
            'stored_path' => str_repeat('b', 32) . '.png',
            'mime' => 'image/png',
            'alt_text' => '',
            'decorative' => 1,
        ]));
        self::assertSame(1, $decorative['decorative']);

        $invalid = [
            $this->mediaInput(['alt_text' => '', 'decorative' => 0]),
            $this->mediaInput(['stored_path' => '../evil.png']),
            $this->mediaInput(['stored_path' => 'nope.jpg']),
            $this->mediaInput(['mime' => 'image/gif']),
            $this->mediaInput(['size' => 0]),
            $this->mediaInput(['size' => 5242881]),
            $this->mediaInput(['original_name' => '../traversal.jpg']),
        ];
        $rejected = 0;
        foreach ($invalid as $input) {
            try {
                $this->media->create($input);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalid), $rejected);
        self::assertSame(2, count($this->media->list()));
    }

    public function testMediaEndpointsRequireAuthentication(): void
    {
        $response = $this->controller->index($this->get());
        self::assertSame(302, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);

        $response = $this->controller->upload($this->post([Csrf::FORM_FIELD => $this->csrf->token()]), []);
        self::assertSame(302, $response->status());
    }

    public function testUploadRejectsBadCsrfAndWrongMethod(): void
    {
        $this->authenticate();

        $get = $this->controller->upload($this->get(), []);
        self::assertSame(405, $get->status());

        $badCsrf = $this->controller->upload($this->post([Csrf::FORM_FIELD => 'invalid']), []);
        self::assertSame(400, $badCsrf->status());
        self::assertSame([], $this->media->list());
    }

    public function testIndexListsMediaWithEscapedNamesAndShortNavigation(): void
    {
        $this->media->create([
            'original_name' => '<img src=x>.png',
            'stored_path' => str_repeat('c', 32) . '.png',
            'mime' => 'image/png',
            'size' => 10,
            'alt_text' => 'An image',
            'decorative' => 0,
        ]);
        $this->authenticate();

        $response = $this->controller->index($this->get());

        self::assertSame(200, $response->status());
        self::assertStringContainsString('&lt;img src=x&gt;.png', $response->body());
        self::assertStringNotContainsString('<img src=x>', $response->body());
        self::assertStringContainsString('media.php?id=', $response->body());
        foreach (['Pages', 'Media', 'Settings', 'Menu', 'Admin', 'Log out'] as $label) {
            self::assertStringContainsString($label, $response->body());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mediaInput(array $overrides = []): array
    {
        return array_merge([
            'original_name' => 'photo.jpg',
            'stored_path' => str_repeat('d', 32) . '.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'alt_text' => 'A photo',
            'decorative' => 0,
        ], $overrides);
    }

    private function pngFixture(): string
    {
        $path = $this->temporaryRoot . '/fixture.png';
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function jpegFixture(): string
    {
        $path = $this->temporaryRoot . '/fixture.jpg';
        $image = imagecreatetruecolor(2, 2);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function webpFixture(): string
    {
        $path = $this->temporaryRoot . '/fixture.webp';
        $image = imagecreatetruecolor(2, 2);
        imagewebp($image, $path);
        imagedestroy($image);

        return $path;
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
