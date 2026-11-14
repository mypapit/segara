<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use RuntimeException;
use Segara\Admin\PageController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class PageEditorUiTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private AdminRepository $admins;
    private PageRepository $pages;
    private MediaRepository $media;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private PageController $controller;
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
        $this->admins = new AdminRepository($this->pdo);
        $this->pages = new PageRepository($this->pdo);
        $this->media = new MediaRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new PageController(
            $this->config,
            $this->pages,
            $this->admins,
            $this->session,
            $this->csrf,
            $this->media,
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



    public function testTheFormLinksToPreview(): void
    {
        $page = $this->pages->create(['title' => 'Draft', 'slug' => 'draft-page', 'state' => 'draft', 'author_name' => 'Site Administrator']);
        $this->authenticate();

        $body = $this->controller->edit($this->get(['id' => $page['id']]))->body();

        self::assertStringContainsString('action=page-preview', $body);
        self::assertStringContainsString('>Preview</a>', $body);
        self::assertStringContainsString('Only you can see the preview.', $body);
    }

    public function testUnsavedPagesExplainWhyPreviewIsUnavailable(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringNotContainsString('action=page-preview', $body);
        self::assertStringContainsString('Save the draft first, then preview.', $body);
    }

    public function testTheGuideIsADefinitionListWiredToTheTextarea(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('<dl class="format-guide" id="format-guide">', $body);
        self::assertStringContainsString('aria-describedby="body-help format-guide"', $body);
        self::assertStringContainsString('id="body-help"', $body);
    }

    public function testTheGuideUsesTheRoutableMediaUrl(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('media.php?id=', $body);
        self::assertStringNotContainsString('/media/', $body);
    }

    public function testTheGuideDoesNotPromiseTables(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        // Verified against the real pipeline: a table is dropped entirely, and raw
        // HTML survives only as escaped text.
        self::assertStringContainsString('Tables are removed completely', $body);
        self::assertStringNotContainsString('<dt><code>|', $body);
    }

    public function testTheMediaPickerRendersStoredAltText(): void
    {
        $item = $this->media->create([
            'original_name' => 'chart.png', 'stored_path' => str_repeat('ab', 16) . '.png', 'mime' => 'image/png',
            'size' => 2048, 'alt_text' => 'Enrolment by year', 'decorative' => 0,
        ]);
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('data-snippet="![Enrolment by year](media.php?id=' . $item['id'] . ')"', $body);
        self::assertStringContainsString('src="media.php?id=' . $item['id'] . '"', $body);
        self::assertStringContainsString('chart.png', $body);
    }

    public function testDecorativeImagesGetAnEmptyAltSnippet(): void
    {
        $item = $this->media->create([
            'original_name' => 'divider.png', 'stored_path' => str_repeat('cd', 16) . '.png', 'mime' => 'image/png',
            'size' => 1024, 'alt_text' => '', 'decorative' => 1,
        ]);
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('data-snippet="![](media.php?id=' . $item['id'] . ')"', $body);
        self::assertStringContainsString('Decorative', $body);
    }

    public function testAnAltTextWithBracketsStillProducesRenderableMarkdown(): void
    {
        $item = $this->media->create([
            'original_name' => 'price.png', 'stored_path' => str_repeat('ef', 16) . '.png', 'mime' => 'image/png',
            'size' => 512, 'alt_text' => 'Price ] per unit', 'decorative' => 0,
        ]);
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        // An unbalanced bracket closes the Markdown label early, so the snippet
        // would publish as text or as a link instead of an image.
        self::assertStringContainsString('data-snippet="![Price ) per unit](media.php?id=' . $item['id'] . ')"', $body);
        self::assertStringNotContainsString('![Price ] per unit]', $body);
    }

    public function testTheSnippetIsSelectableTextOutsideTheInsertButton(): void
    {
        $this->media->create([
            'original_name' => 'chart.png', 'stored_path' => str_repeat('ab', 16) . '.png', 'mime' => 'image/png',
            'size' => 2048, 'alt_text' => 'Enrolment by year', 'decorative' => 0,
        ]);
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertMatchesRegularExpression('/<button[^>]*data-media-insert[^>]*>.*?<\/button>/s', $body);
        $found = preg_match('/<button[^>]*data-media-insert[^>]*>(.*?)<\/button>/s', $body, $match);
        self::assertSame(1, $found);
        $buttonInner = $match[1];
        self::assertStringNotContainsString('media-picker__snippet', $buttonInner, 'a keyboard user must be able to select the snippet');
        self::assertStringContainsString('<code class="media-picker__snippet">', $body);
    }

    public function testThePickerExplainsItselfWhenNoMediaExists(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('No images yet', $body);
    }

    public function testTheFormIsMarkedForTheUnsavedGuard(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('data-page-form', $body);
        self::assertStringContainsString('/assets/admin.js', $body);
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


    /** @param array<string, string> $query */
    private function get(array $query = []): Request
    {
        $server = ['REQUEST_METHOD' => 'GET'];
        if ($query !== []) {
            $server['QUERY_STRING'] = http_build_query($query);
        }

        return Request::fromArrays($server);
    }

    private function authenticate(): void
    {
        $admin = $this->admins->findById($this->adminId);
        self::assertNotNull($admin);
        $this->session->login($admin['id'], $admin['auth_epoch']);
    }

}
