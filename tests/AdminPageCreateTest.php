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
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class AdminPageCreateTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private AdminRepository $admins;
    private PageRepository $pages;
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

    public function testEveryEndpointRequiresAuthentication(): void
    {
        $response = $this->controller->index($this->get());
        self::assertSame(302, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);

        $response = $this->controller->create($this->post($this->validDraftPost()));
        self::assertSame(302, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);

        self::assertSame([], $this->pages->listAdmin());
    }

    public function testIndexListsPagesWithTextualStatesAndShortNavigation(): void
    {
        $this->pages->create([
            'title' => '<b>Draft Page</b>', 'slug' => 'draft-page', 'state' => 'draft', 'author_name' => 'Site Administrator',
        ]);
        $this->pages->create([
            'title' => 'Live Page', 'slug' => 'live-page', 'state' => 'published', 'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $response = $this->controller->index($this->get());

        self::assertSame(200, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertStringContainsString('&lt;b&gt;Draft Page&lt;/b&gt;', $response->body());
        self::assertStringNotContainsString('<b>Draft Page</b>', $response->body());
        self::assertStringContainsString('Draft', $response->body());
        self::assertStringContainsString('Published', $response->body());
        self::assertStringNotContainsString('password_hash', $response->body());
        foreach (['Pages', 'Media', 'Settings', 'Menu', 'Admin', 'Log out'] as $label) {
            self::assertStringContainsString($label, $response->body());
        }
    }

    public function testCreateFormGetDoesNotPublishAnything(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->get());

        self::assertSame(200, $response->status());
        // The state menu is the primary control; "Save and publish" is the shortcut.
        self::assertStringContainsString('name="state"', $response->body());
        self::assertStringContainsString('name="intent" value="publish"', $response->body());
        self::assertSame([], $this->pages->listAdmin());
    }

    public function testCreateSavesDraftWithDerivedSlugAndAuthorDefaults(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->validDraftPost()));

        self::assertSame(303, $response->status());
        self::assertSame('/admin.php?action=pages&notice=saved', $response->headers()['Location']);
        $page = $this->pages->getBySlug('cafe-notes');
        self::assertNotNull($page);
        self::assertSame('draft', $page['state']);
        self::assertSame('Site Administrator', $page['author_name']);
        self::assertSame($this->adminId, $page['author_id']);
        self::assertSame(1, $page['revision']);
        self::assertNull($page['published_at']);
    }

    public function testCreatePublishesAndSetsPublicationTime(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->validDraftPost(['intent' => 'publish'])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('cafe-notes');
        self::assertNotNull($page);
        self::assertSame('published', $page['state']);
        self::assertNotNull($page['published_at']);
    }

    public function testPublishedPageDefaultsToListOnHomepage(): void
    {
        $this->authenticate();

        // No explicit placement: publishing must still surface on the homepage.
        $response = $this->controller->create($this->post($this->validDraftPost(['intent' => 'publish'])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('cafe-notes');
        self::assertNotNull($page);
        self::assertSame('published', $page['state']);
        self::assertSame('home', $page['placement']);

        $homepage = $this->pages->listHomepage();
        self::assertCount(1, $homepage);
        self::assertSame('cafe-notes', $homepage[0]['slug']);
    }

    public function testCreateRejectsInvalidCsrfBeforeWriting(): void
    {
        $this->authenticate();
        $post = $this->validDraftPost();
        $post[Csrf::FORM_FIELD] = 'invalid';

        $response = $this->controller->create($this->post($post));

        self::assertSame(400, $response->status());
        self::assertStringContainsString('Invalid request.', $response->body());
        self::assertSame([], $this->pages->listAdmin());
    }

    public function testCreateRejectsInvalidInputAndPreservesEnteredValues(): void
    {
        $this->authenticate();
        $invalidPosts = [
            $this->validDraftPost(['title' => '']),
            $this->validDraftPost(['title' => str_repeat('x', 201)]),
            $this->validDraftPost(['slug' => 'admin']),
            $this->validDraftPost(['slug' => 'admin.php']),
            $this->validDraftPost(['description' => str_repeat('x', 501)]),
        ];

        foreach ($invalidPosts as $post) {
            $response = $this->controller->create($this->post($post));
            self::assertSame(422, $response->status());
            self::assertStringContainsString('role="alert"', $response->body());
        }
        self::assertSame([], $this->pages->listAdmin());

        $preserve = $this->validDraftPost(['title' => '']);
        $response = $this->controller->create($this->post($preserve));
        self::assertStringContainsString('name="body_markdown"', $response->body());
    }

    public function testCreateRejectsUnexpectedIntentAndIgnoresInjectedState(): void
    {
        $this->authenticate();

        $badIntent = $this->controller->create($this->post($this->validDraftPost(['intent' => 'trash'])));
        self::assertSame(422, $badIntent->status());
        self::assertSame([], $this->pages->listAdmin());

        // id, revision and author_id come from the request but are never trusted:
        // the id is generated, the revision starts at 1, and an unknown author
        // falls back to the signed-in account.
        $injected = $this->validDraftPost(['state' => 'trash', 'id' => str_repeat('f', 32), 'revision' => 999, 'author_id' => str_repeat('0', 32)]);
        $response = $this->controller->create($this->post($injected));
        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('cafe-notes');
        self::assertNotNull($page);
        self::assertSame('trash', $page['state'], 'the status menu is honoured');
        self::assertSame(1, $page['revision']);
        self::assertSame($this->adminId, $page['author_id']);
    }

    public function testCreateCannotBePerformedWithOtherMethods(): void
    {
        $this->authenticate();
        $head = Request::fromArrays(['REQUEST_METHOD' => 'HEAD']);

        $response = $this->controller->create($head);

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->headers()['Allow']);
        self::assertSame([], $this->pages->listAdmin());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validDraftPost(array $overrides = []): array
    {
        return array_merge([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'intent' => 'draft',
            'title' => 'Café Notes',
            'slug' => '',
            'description' => '',
            'body_markdown' => 'A **bold** claim.',
        ], $overrides);
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
