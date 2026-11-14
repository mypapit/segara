<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use RuntimeException;
use Segara\Admin\PageController;
use Segara\Admin\PreviewController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Segara\View\Renderer;
use Tests\Support\TestCase;

final class AdminPageEditTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private AdminRepository $admins;
    private PageRepository $pages;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private PageController $controller;
    private PreviewController $preview;
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
        $this->preview = new PreviewController($this->config, $this->pages, $this->admins, $this->session, new Renderer($this->config));
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
        $page = $this->createPage('draft', 'Draft Page');
        $requests = [
            'edit' => $this->get('id=' . $page['id']),
            'trash' => $this->post($this->trashPost($page)),
            'delete' => $this->get('id=' . $page['id']),
        ];
        foreach ($requests as $method => $request) {
            $response = $this->controller->{$method}($request);
            self::assertSame(302, $response->status(), $method);
            self::assertSame('/admin.php?action=login', $response->headers()['Location'], $method);
        }

        $preview = $this->preview->preview($this->get('id=' . $page['id']));
        self::assertSame(302, $preview->status());
        self::assertSame('/admin.php?action=login', $preview->headers()['Location']);
    }

    public function testEditGetShowsPrefilledFormAndDoesNotWrite(): void
    {
        $page = $this->createPage('draft', 'Café Notes', 'A **bold** claim.');
        $this->authenticate();

        $response = $this->controller->edit($this->get('id=' . $page['id']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="page_id" value="' . $page['id'] . '"', $response->body());
        self::assertStringContainsString('name="revision" value="1"', $response->body());
        self::assertStringContainsString('value="Café Notes"', $response->body());
        self::assertStringContainsString('A **bold** claim.', $response->body());
        self::assertStringContainsString('Move to trash', $response->body());
        self::assertSame('draft', $this->mustGetPage($page['id'])['state']);
    }

    public function testEditUpdatesRevisionSafely(): void
    {
        $page = $this->createPage('draft', 'Original', 'Old body');
        $this->authenticate();

        $post = $this->editPost($page, ['title' => 'Renamed', 'body_markdown' => 'New body', 'intent' => 'publish']);
        $response = $this->controller->edit($this->post($post));

        self::assertSame(303, $response->status());
        self::assertSame('/admin.php?action=pages&notice=saved', $response->headers()['Location']);
        $updated = $this->mustGetPage($page['id']);
        self::assertSame('Renamed', $updated['title']);
        self::assertSame('New body', $updated['body_markdown']);
        self::assertSame('published', $updated['state']);
        self::assertSame(2, $updated['revision']);
    }

    public function testStaleEditReturns409WithoutOverwritingAndHasNoForceFlag(): void
    {
        $page = $this->createPage('draft', 'Original');
        $this->authenticate();

        $this->pages->update($page['id'], 1, ['title' => 'Newer from another tab']);
        $post = $this->editPost($page, ['title' => 'Stale overwrite', 'revision' => '1']);

        $response = $this->controller->edit($this->post($post));

        self::assertSame(409, $response->status());
        self::assertStringContainsString('newer revision', $response->body());
        self::assertStringNotContainsString('name="force"', $response->body());
        self::assertSame('Newer from another tab', $this->mustGetPage($page['id'])['title']);
    }

    public function testPreviewRendersSanitizedMarkdownAndNeverPublishes(): void
    {
        $page = $this->createPage('draft', 'Preview Page', "# Heading\n<script>alert(1)</script>");
        $this->authenticate();

        $response = $this->preview->preview($this->get('id=' . $page['id']));

        self::assertSame(200, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertStringContainsString('<h1>Heading</h1>', $response->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
        self::assertSame('draft', $this->mustGetPage($page['id'])['state']);
        self::assertSame(1, $this->mustGetPage($page['id'])['revision']);
    }

    public function testTrashMovesPageToTrashViaPost(): void
    {
        $page = $this->createPage('published', 'To Trash');
        $this->authenticate();

        $response = $this->controller->trash($this->post($this->trashPost($page)));

        self::assertSame(303, $response->status());
        self::assertSame('trash', $this->mustGetPage($page['id'])['state']);
    }

    public function testTrashRejectsStaleRevisionAndGetMethod(): void
    {
        $page = $this->createPage('published', 'To Trash');
        $this->authenticate();

        $get = $this->controller->trash($this->get());
        self::assertSame(405, $get->status());

        $this->pages->update($page['id'], 1, ['title' => 'Changed']);
        $response = $this->controller->trash($this->post($this->trashPost($page)));
        self::assertSame(409, $response->status());
        self::assertSame('published', $this->mustGetPage($page['id'])['state']);
    }

    public function testDeleteRequiresExactTitleConfirmationAndCannotBeDoneViaGet(): void
    {
        $page = $this->createPage('published', 'Permanent Target');
        $this->authenticate();

        $get = $this->controller->delete($this->get('id=' . $page['id']));
        self::assertSame(200, $get->status());
        self::assertStringContainsString('Type the exact page title', $get->body());
        self::assertNotNull($this->pages->getById($page['id']));

        $wrong = $this->controller->delete($this->post($this->deletePost($page, 'Wrong title')));
        self::assertSame(422, $wrong->status());
        self::assertNotNull($this->pages->getById($page['id']));

        $right = $this->controller->delete($this->post($this->deletePost($page, 'Permanent Target')));
        self::assertSame(303, $right->status());
        self::assertNull($this->pages->getById($page['id']));
    }

    public function testDeleteRejectsBadCsrfAndStaleRevision(): void
    {
        $page = $this->createPage('published', 'Guarded');
        $this->authenticate();

        $badCsrf = $this->deletePost($page, 'Guarded');
        $badCsrf['_csrf'] = 'invalid';
        self::assertSame(400, $this->controller->delete($this->post($badCsrf))->status());
        self::assertNotNull($this->pages->getById($page['id']));

        $this->pages->update($page['id'], 1, ['title' => 'Guarded v2']);
        $stale = $this->controller->delete($this->post($this->deletePost($page, 'Guarded v2')));
        self::assertSame(409, $stale->status());
        self::assertNotNull($this->pages->getById($page['id']));
        self::assertSame($this->adminId, $this->session->adminId());
    }

    public function testHomepageCannotBeTrashedOrDeletedUntilReplaced(): void
    {
        $page = $this->createPage('published', 'The Homepage');
        $this->setHomepage($page['id']);
        $this->authenticate();

        $trash = $this->controller->trash($this->post($this->trashPost($page)));
        self::assertSame(422, $trash->status());
        self::assertSame('published', $this->mustGetPage($page['id'])['state']);

        $delete = $this->controller->delete($this->post($this->deletePost($page, 'The Homepage')));
        self::assertSame(422, $delete->status());
        self::assertNotNull($this->pages->getById($page['id']));
    }

    /**
     * @return array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, tags: list<string>}
     */
    private function createPage(string $state, string $title, string $body = ''): array
    {
        return $this->pages->create([
            'title' => $title,
            'slug' => '',
            'body_markdown' => $body,
            'state' => $state,
            'author_name' => 'Site Administrator',
        ]);
    }

    /** @param array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, tags: list<string>} $page
     * @return array<string, mixed>
     */
    private function trashPost(array $page): array
    {
        return [
            Csrf::FORM_FIELD => $this->csrf->token(),
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
        ];
    }

    /**
     * @param array{id: string, revision: int} $page
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function editPost(array $page, array $overrides = []): array
    {
        return array_merge([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'intent' => 'draft',
            'title' => 'Renamed',
            'slug' => '',
            'description' => '',
            'body_markdown' => 'Body',
        ], $overrides);
    }

    /**
     * @param array{id: string, revision: int, title: string} $page
     * @return array<string, mixed>
     */
    private function deletePost(array $page, string $confirmation): array
    {
        return [
            Csrf::FORM_FIELD => $this->csrf->token(),
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'confirmation' => $confirmation,
        ];
    }

    private function setHomepage(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, revision, updated_at) VALUES (:key, :value, 1, :updated)'
        );
        $statement->execute(['key' => 'homepage_id', 'value' => $id, 'updated' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /**
     * @return array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, tags: list<string>}
     */
    private function mustGetPage(string $id): array
    {
        $page = $this->pages->getById($id);
        self::assertNotNull($page);

        return $page;
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

    private function get(string $query = ''): Request
    {
        return Request::fromArrays(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => $query]);
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
