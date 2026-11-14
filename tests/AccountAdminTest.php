<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\AccountController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class AccountAdminTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';
    private const NEW_PASSWORD = 'a different correct password';

    private PDO $pdo;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private AccountController $controller;
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
        $admin = $this->admins->create('admin', 'Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->config = Config::fromArray([
            'base_url' => 'https://example.test',
            'environment' => 'test',
        ]);
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new AccountController($this->config, $this->admins, $this->session, $this->csrf);
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

    public function testEveryAccountEndpointRequiresAuthentication(): void
    {
        $requests = [
            'index' => $this->get(),
            'create' => $this->post($this->validCreatePost()),
            'changePassword' => $this->post($this->validPasswordPost()),
            'delete' => $this->post($this->validDeletePost()),
            'logout' => $this->post([Csrf::FORM_FIELD => $this->csrf->token()]),
        ];

        foreach ($requests as $method => $request) {
            $response = $this->controller->{$method}($request);
            self::assertSame(302, $response->status(), $method);
            self::assertSame('/admin.php?action=login', $response->headers()['Location'], $method);
            self::assertSame('no-store, private', $response->headers()['Cache-Control'], $method);
        }
        self::assertSame(1, $this->admins->count());
        self::assertTrue($this->passwordIs(self::PASSWORD));
    }

    public function testIndexListsEscapedAdminsAndShortNavigationWithoutSecrets(): void
    {
        $this->admins->create('second', '<img src=x onerror=alert(1)> & Co', self::PASSWORD);
        $this->authenticate();

        $response = $this->controller->index($this->get());

        self::assertSame(200, $response->status());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertSame('noindex, nofollow', $this->metaRobots($response));
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; Co', $response->body());
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->body());
        self::assertStringNotContainsString(self::PASSWORD, $response->body());
        self::assertStringNotContainsString('password_hash', $response->body());
        self::assertStringNotContainsString('auth_epoch', $response->body());
        foreach (['Pages', 'Media', 'Settings', 'Menu', 'Admin', 'Log out'] as $label) {
            self::assertStringContainsString($label, $response->body());
        }
        self::assertSame(2, substr_count($response->body(), 'name="password"'));
        self::assertSame(1, substr_count($response->body(), 'name="current_password"'));
        self::assertStringContainsString('method="post"', $response->body());
    }

    public function testCreateAddsEqualAdminAndIgnoresRoleAndStatusInjection(): void
    {
        $this->authenticate();
        $post = $this->validCreatePost() + ['role' => 'superadmin', 'status' => 'disabled', 'id' => str_repeat('f', 32)];

        $response = $this->controller->create($this->post($post));

        self::assertSame(303, $response->status());
        self::assertSame('/admin.php?action=accounts', $response->headers()['Location']);
        self::assertSame(2, $this->admins->count());
        $created = $this->admins->findByUsername('second');
        self::assertNotNull($created);
        self::assertNotSame(str_repeat('f', 32), $created['id']);
        self::assertSame('Second Administrator', $created['display_name']);
        self::assertSame(1, $created['auth_epoch']);
        self::assertArrayNotHasKey('role', $created);
        self::assertArrayNotHasKey('status', $created);
        self::assertNotNull($this->admins->verifyAndRehashPassword('second', self::NEW_PASSWORD));
    }

    public function testCreateRejectsInvalidCsrfBeforeWriting(): void
    {
        $this->authenticate();
        $post = $this->validCreatePost();
        $post[Csrf::FORM_FIELD] = 'invalid';

        $response = $this->controller->create($this->post($post));

        self::assertSame(400, $response->status());
        self::assertStringContainsString('Invalid request.', $response->body());
        self::assertSame(1, $this->admins->count());
        self::assertStringNotContainsString(self::NEW_PASSWORD, $response->body());
    }

    public function testCreateRejectsInvalidInputAndDuplicateUsernameWithoutEchoingPasswords(): void
    {
        $this->authenticate();
        $invalidPosts = [
            $this->validCreatePost(['username' => 'x']),
            $this->validCreatePost(['username' => ['nested']]),
            $this->validCreatePost(['display_name' => '']),
            $this->validCreatePost(['display_name' => str_repeat('x', 201)]),
            $this->validCreatePost(['password' => 'short', 'password_confirmation' => 'short']),
            $this->validCreatePost(['password_confirmation' => 'does not match']),
            $this->validCreatePost(['username' => 'admin']),
        ];

        foreach ($invalidPosts as $post) {
            $response = $this->controller->create($this->post($post));
            self::assertSame(422, $response->status());
            self::assertStringContainsString('role="alert"', $response->body());
            self::assertStringNotContainsString(self::NEW_PASSWORD, $response->body());
        }
        self::assertSame(1, $this->admins->count());
    }

    public function testCreateCannotBePerformedWithGet(): void
    {
        $this->authenticate();

        $response = $this->controller->create($this->get());

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->headers()['Allow']);
        self::assertSame(1, $this->admins->count());
    }

    public function testOwnPasswordChangeRequiresCurrentPasswordAndRotatesAuthenticationAndCsrf(): void
    {
        $this->authenticate();
        $oldSessionId = $this->session->id();
        $oldCsrf = $this->csrf->token();
        $oldAdmin = $this->admins->findById($this->adminId);
        self::assertNotNull($oldAdmin);
        $oldEpoch = $oldAdmin['auth_epoch'];

        $response = $this->controller->changePassword($this->post($this->validPasswordPost()));

        self::assertSame(303, $response->status());
        self::assertNotSame($oldSessionId, $this->session->id());
        $updated = $this->admins->findById($this->adminId);
        self::assertNotNull($updated);
        self::assertSame($oldEpoch + 1, $updated['auth_epoch']);
        self::assertTrue($this->session->hasValidAuthentication($updated['auth_epoch']));
        self::assertFalse($this->session->hasValidAuthentication($oldEpoch));
        self::assertFalse($this->csrf->validate($this->post([Csrf::FORM_FIELD => $oldCsrf])));
        self::assertNull($this->admins->verifyAndRehashPassword('admin', self::PASSWORD));
        self::assertNotNull($this->admins->verifyAndRehashPassword('admin', self::NEW_PASSWORD));
    }

    public function testPasswordChangeRejectsWrongCurrentPasswordAndInvalidInput(): void
    {
        $this->authenticate();
        $originalHash = $this->admins->findById($this->adminId)['password_hash'] ?? null;
        $invalidPosts = [
            $this->validPasswordPost(['current_password' => 'wrong current password']),
            $this->validPasswordPost(['current_password' => ['nested']]),
            $this->validPasswordPost(['current_password' => str_repeat('x', 4097)]),
            $this->validPasswordPost(['current_password' => "invalid\0password"]),
            $this->validPasswordPost(['password' => 'short', 'password_confirmation' => 'short']),
            $this->validPasswordPost(['password_confirmation' => 'does not match']),
            $this->validPasswordPost(['admin_id' => ['nested']]),
        ];

        foreach ($invalidPosts as $post) {
            $response = $this->controller->changePassword($this->post($post));
            self::assertSame(422, $response->status());
            self::assertStringContainsString('role="alert"', $response->body());
            self::assertStringNotContainsString(self::NEW_PASSWORD, $response->body());
        }
        self::assertSame($originalHash, $this->admins->findById($this->adminId)['password_hash'] ?? null);
    }

    public function testPasswordChangeRejectsInvalidCsrf(): void
    {
        $this->authenticate();
        $post = $this->validPasswordPost();
        $post[Csrf::FORM_FIELD] = 'invalid';
        $originalHash = $this->admins->findById($this->adminId)['password_hash'] ?? null;

        $response = $this->controller->changePassword($this->post($post));

        self::assertSame(400, $response->status());
        self::assertSame($originalHash, $this->admins->findById($this->adminId)['password_hash'] ?? null);
    }

    public function testPasswordChangeCannotTargetAnotherOrUnknownAdministrator(): void
    {
        $other = $this->admins->create('second', 'Second Administrator', self::PASSWORD);
        $otherHash = $other['password_hash'];
        $this->authenticate();

        $forbidden = $this->controller->changePassword($this->post($this->validPasswordPost(['admin_id' => $other['id']])));
        $missing = $this->controller->changePassword($this->post($this->validPasswordPost(['admin_id' => str_repeat('0', 32)])));

        self::assertSame(403, $forbidden->status());
        self::assertSame(404, $missing->status());
        self::assertSame($otherHash, $this->admins->findById($other['id'])['password_hash'] ?? null);
        self::assertTrue($this->passwordIs(self::PASSWORD));
    }

    public function testDeleteRemovesAnotherAdministrator(): void
    {
        $other = $this->admins->create('second', 'Second Administrator', self::PASSWORD);
        $this->authenticate();

        $response = $this->controller->delete($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'admin_id' => $other['id'],
        ]));

        self::assertSame(303, $response->status());
        self::assertSame('/admin.php?action=accounts', $response->headers()['Location']);
        self::assertNull($this->admins->findById($other['id']));
        self::assertSame(1, $this->admins->count());
    }

    public function testDeleteRejectsBadCsrfMalformedUnknownAndCurrentIds(): void
    {
        $other = $this->admins->create('second', 'Second Administrator', self::PASSWORD);
        $this->authenticate();
        $token = $this->csrf->token();
        $cases = [
            ['post' => ['_csrf' => 'bad', 'admin_id' => $other['id']], 'status' => 400],
            ['post' => ['_csrf' => $token, 'admin_id' => ['nested']], 'status' => 422],
            ['post' => ['_csrf' => $token, 'admin_id' => 'not-an-id'], 'status' => 422],
            ['post' => ['_csrf' => $token, 'admin_id' => str_repeat('0', 32)], 'status' => 404],
            ['post' => ['_csrf' => $token, 'admin_id' => $this->adminId], 'status' => 422],
        ];

        foreach ($cases as $case) {
            $response = $this->controller->delete($this->post($case['post']));
            self::assertSame($case['status'], $response->status());
        }
        self::assertSame(2, $this->admins->count());
        self::assertNotNull($this->session->adminId());
    }

    public function testRepositoryAtomicallyRefusesFinalAdministratorDeletion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('final administrator');
        $this->admins->delete($this->adminId, str_repeat('0', 32));
    }

    public function testDeleteAndPasswordChangeCannotBePerformedWithGet(): void
    {
        $other = $this->admins->create('second', 'Second Administrator', self::PASSWORD);
        $this->authenticate();

        $delete = $this->controller->delete($this->get());
        $password = $this->controller->changePassword($this->get());

        self::assertSame(405, $delete->status());
        self::assertSame(405, $password->status());
        self::assertSame(2, $this->admins->count());
        self::assertNotNull($this->admins->findById($other['id']));
        self::assertTrue($this->passwordIs(self::PASSWORD));
    }

    public function testLogoutRequiresPostAndValidCsrfAndThenRegeneratesAndDestroysSession(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();
        $before = $this->session->id();

        $get = $this->controller->logout($this->get());
        self::assertSame(405, $get->status());
        self::assertSame($this->adminId, $this->session->adminId());

        $badCsrf = $this->controller->logout($this->post([Csrf::FORM_FIELD => 'invalid']));
        self::assertSame(400, $badCsrf->status());
        self::assertSame($this->adminId, $this->session->adminId());

        $response = $this->controller->logout($this->post([Csrf::FORM_FIELD => $token]));
        self::assertSame(303, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame('', session_id());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertNotSame($before, $this->session->id());
        self::assertNull($this->session->adminId());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validCreatePost(array $overrides = []): array
    {
        return array_merge([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'username' => 'second',
            'display_name' => 'Second Administrator',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPasswordPost(array $overrides = []): array
    {
        return array_merge([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'admin_id' => $this->adminId,
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function validDeletePost(): array
    {
        return [Csrf::FORM_FIELD => $this->csrf->token(), 'admin_id' => str_repeat('0', 32)];
    }

    private function authenticate(): void
    {
        $admin = $this->admins->findById($this->adminId);
        self::assertNotNull($admin);
        $this->session->login($admin['id'], $admin['auth_epoch']);
    }

    private function passwordIs(string $password): bool
    {
        return $this->admins->verifyAndRehashPassword('admin', $password) !== null;
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

    private function metaRobots(Response $response): string
    {
        $matched = preg_match('/<meta name="robots" content="([^"]+)">/', $response->body(), $matches);
        self::assertSame(1, $matched);
        return $matches[1];
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
