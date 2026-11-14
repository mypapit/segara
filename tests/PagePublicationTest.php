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

final class PagePublicationTest extends TestCase
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


    public function testAPageCanBeCreatedAsPrivate(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost([
            'state' => 'private',
            'password' => 'open-sesame-2026',
        ])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('publication-test');
        self::assertNotNull($page);
        self::assertSame('private', $page['state']);
        self::assertNotNull($page['password_hash']);
        self::assertTrue(password_verify('open-sesame-2026', (string) $page['password_hash']));
        self::assertStringNotContainsString('open-sesame-2026', json_encode($page) ?: '');
    }

    public function testPrivateWithoutPasswordIsRejected(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost(['state' => 'private'])));

        self::assertSame(422, $response->status());
        self::assertStringContainsStringIgnoringCase('password', $response->body());
        self::assertNull($this->pages->getBySlug('publication-test'));
    }

    public function testPrivatePageKeepsItsHashWhenTheFieldIsLeftBlank(): void
    {
        $page = $this->pages->create([
            'title' => 'Secret', 'slug' => 'secret', 'state' => 'private',
            'password_hash' => password_hash('open-sesame-2026', PASSWORD_DEFAULT),
            'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Secret',
            'slug' => 'secret',
            'state' => 'private',
            'password' => '',
        ])));

        self::assertSame(303, $response->status());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame($page['password_hash'], $stored['password_hash']);
    }

    public function testAnUnprivatisedPageLosesItsHash(): void
    {
        $page = $this->pages->create([
            'title' => 'Secret', 'slug' => 'secret', 'state' => 'private',
            'password_hash' => password_hash('open-sesame-2026', PASSWORD_DEFAULT),
            'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Secret',
            'slug' => 'secret',
            'state' => 'published',
            'password' => '',
        ])));

        self::assertSame(303, $response->status());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('published', $stored['state']);
        self::assertNull($stored['password_hash']);
    }

    public function testATrashedPageCanBeRestoredAsDraft(): void
    {
        $page = $this->pages->create(['title' => 'Gone', 'slug' => 'gone', 'state' => 'trash', 'author_name' => 'Site Administrator']);
        $this->authenticate();

        $response = $this->controller->restore($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
        ]));

        self::assertSame(303, $response->status());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('draft', $stored['state'], 'restore must never republish');
    }

    public function testTheHomepageCannotBeTrashed(): void
    {
        $page = $this->pages->create(['title' => 'Home', 'slug' => 'home-page', 'state' => 'published', 'author_name' => 'Site Administrator']);
        $insert = $this->pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :now)');
        $insert->execute(['key' => 'homepage_id', 'value' => $page['id'], 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Home',
            'slug' => 'home-page',
            'state' => 'trash',
        ])));

        self::assertSame(422, $response->status());
        self::assertStringContainsStringIgnoringCase('homepage', $response->body());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('published', $stored['state']);
    }

    public function testPublishedDateIsPreservedOnEdit(): void
    {
        $date = '2026-03-01T00:00:00Z';
        $page = $this->pages->create([
            'title' => 'Dated', 'slug' => 'dated', 'state' => 'published',
            'published_at' => $date, 'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Dated',
            'slug' => 'dated',
            'state' => 'published',
            'published_at' => '2026-03-01',
        ])));

        self::assertSame(303, $response->status());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame($date, $stored['published_at']);
    }

    public function testAPublicationDateIsConvertedToUtcMidnight(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost([
            'state' => 'published',
            'published_at' => '2026-04-09',
        ])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('publication-test');
        self::assertNotNull($page);
        self::assertSame('2026-04-09T00:00:00Z', $page['published_at']);
    }

    public function testTagsAreSynchronised(): void
    {
        $this->authenticate();

        $this->controller->create($this->post($this->basePost(['tags' => ' teaching , notes ,,' ])));
        $page = $this->pages->getBySlug('publication-test');
        self::assertNotNull($page);
        self::assertSame(['teaching', 'notes'], $page['tags']);

        $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'tags' => 'notes',
            'state' => $page['state'],
        ])));

        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame(['notes'], $stored['tags']);
    }

    public function testTheAuthorIsResolvedFromTheAccountList(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost(['author_id' => $this->adminId])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('publication-test');
        self::assertNotNull($page);
        self::assertSame($this->adminId, $page['author_id']);
        self::assertSame('Site Administrator', $page['author_name']);
    }

    public function testUnknownStateIsRejected(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost(['state' => 'archived'])));

        self::assertSame(422, $response->status());
        self::assertStringContainsStringIgnoringCase('state', $response->body());
        self::assertNull($this->pages->getBySlug('publication-test'));
    }

    public function testSaveAndPublishWinsOverADraftSelection(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost([
            'state' => 'draft',
            'intent' => 'publish',
        ])));

        self::assertSame(303, $response->status());
        $page = $this->pages->getBySlug('publication-test');
        self::assertNotNull($page);
        self::assertSame('published', $page['state']);
    }

    public function testTheListFiltersByState(): void
    {
        $this->pages->create(['title' => 'Live One', 'slug' => 'live-one', 'state' => 'published', 'author_name' => 'Site Administrator']);
        $this->pages->create(['title' => 'Draft One', 'slug' => 'draft-one', 'state' => 'draft', 'author_name' => 'Site Administrator']);
        $this->authenticate();

        $filtered = $this->controller->index($this->get(['state' => 'draft']));
        self::assertSame(200, $filtered->status());
        self::assertStringContainsString('Draft One', $filtered->body());
        self::assertStringNotContainsString('Live One', $filtered->body());
        self::assertStringContainsString('aria-current="page"', $filtered->body());

        $all = $this->controller->index($this->get());
        self::assertStringContainsString('Live One', $all->body());
        self::assertStringContainsString('Draft One', $all->body());
    }

    public function testTheFormRendersEveryStateAsWords(): void
    {
        $this->authenticate();

        $body = $this->controller->create($this->get())->body();

        self::assertStringContainsString('Draft — only you can see it', $body);
        self::assertStringContainsString('Published — anyone can read it', $body);
        self::assertStringContainsString('Private — readers need a password', $body);
        self::assertStringContainsString('Trash — hidden, recoverable', $body);
        self::assertStringContainsString('name="published_at"', $body);
        self::assertStringContainsString('name="tags"', $body);
        self::assertStringContainsString('name="author_id"', $body);
    }

    public function testSavingAnUnchangedDateKeepsTheStoredTimeOfDay(): void
    {
        $page = $this->pages->create([
            'title' => 'Timed', 'slug' => 'timed', 'state' => 'published',
            'published_at' => '2026-04-09T14:37:21Z', 'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Timed',
            'slug' => 'timed',
            'state' => 'published',
            'published_at' => '2026-04-09',
        ])));

        self::assertSame(303, $response->status());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('2026-04-09T14:37:21Z', $stored['published_at'], 'the time-of-day must survive a save');
    }

    public function testANewDayReplacesTheStoredInstant(): void
    {
        $page = $this->pages->create([
            'title' => 'Timed', 'slug' => 'timed', 'state' => 'published',
            'published_at' => '2026-04-09T14:37:21Z', 'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Timed',
            'slug' => 'timed',
            'state' => 'published',
            'published_at' => '2026-04-10',
        ])));

        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('2026-04-10T00:00:00Z', $stored['published_at']);
    }

    public function testAnImpossibleCalendarDateIsRejected(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->post($this->basePost([
            'state' => 'published',
            'published_at' => '2026-02-31',
        ])));

        self::assertSame(422, $response->status());
        self::assertStringContainsStringIgnoringCase('calendar date', $response->body());
        self::assertNull($this->pages->getBySlug('publication-test'));
    }

    public function testRestoreRejectsAPageThatIsNotTrashed(): void
    {
        $page = $this->pages->create(['title' => 'Live', 'slug' => 'live', 'state' => 'published', 'author_name' => 'Site Administrator']);
        $this->authenticate();

        $response = $this->controller->restore($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsStringIgnoringCase('trashed page', $response->body());
        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('published', $stored['state'], 'restore must not unpublish a live page');
    }

    public function testTrashingKeepsTheAccessPasswordForALaterRestore(): void
    {
        $page = $this->pages->create([
            'title' => 'Secret', 'slug' => 'secret', 'state' => 'private',
            'password_hash' => password_hash('open-sesame-2026', PASSWORD_DEFAULT),
            'author_name' => 'Site Administrator',
        ]);
        $this->authenticate();

        $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Secret',
            'slug' => 'secret',
            'state' => 'trash',
        ])));

        $stored = $this->pages->getById($page['id']);
        self::assertNotNull($stored);
        self::assertSame('trash', $stored['state']);
        self::assertSame($page['password_hash'], $stored['password_hash'], 'trash is recoverable, so is its password');
    }

    public function testARejectedStateReplaysTheStateThePageIsActuallyIn(): void
    {
        $page = $this->pages->create(['title' => 'Live', 'slug' => 'live', 'state' => 'published', 'author_name' => 'Site Administrator']);
        $this->authenticate();

        $response = $this->controller->edit($this->post($this->basePost([
            'page_id' => $page['id'],
            'revision' => (string) $page['revision'],
            'title' => 'Live',
            'slug' => 'live',
            'state' => 'archived',
        ])));

        self::assertSame(422, $response->status());
        $body = $response->body();
        self::assertStringContainsString('<option value="published" selected>', $body);
        self::assertStringNotContainsString('<option value="draft" selected>', $body);
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

    /** @param array<string, mixed> $post */
    private function post(array $post): Request
    {
        return Request::fromArrays(['REQUEST_METHOD' => 'POST'], $post);
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function basePost(array $overrides = []): array
    {
        return array_merge([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'title' => 'Publication Test',
            'slug' => 'publication-test',
            'description' => '',
            'body_markdown' => 'Body text.',
            'state' => 'draft',
        ], $overrides);
    }
}
