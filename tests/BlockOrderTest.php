<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\BlockController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Blocks\BlockRepository;
use Segara\Config;
use Segara\Http\Request;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class BlockOrderTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private BlockRepository $blocks;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private BlockController $controller;
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
        $this->blocks = new BlockRepository($this->pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->admins = new AdminRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new BlockController($this->config, $this->blocks, $this->admins, $this->session, $this->csrf);
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

    public function testRepositoryReorderPersistsNewOrderAtomically(): void
    {
        $a = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => 'a']]);
        $b = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 1, 'settings' => ['text' => 'b']]);
        $c = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 2, 'settings' => ['text' => 'c']]);

        $this->blocks->reorder('left', [$c['id'], $a['id'], $b['id']]);

        $left = $this->blocks->listByLocation('left');
        self::assertSame([$c['id'], $a['id'], $b['id']], array_column($left, 'id'));
        self::assertSame([0, 1, 2], array_column($left, 'position'));

        // A rejected order must leave the persisted order untouched.
        try {
            $this->blocks->reorder('left', [$b['id'], str_repeat('f', 32)]);
            self::fail('Expected an invalid order to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $left = $this->blocks->listByLocation('left');
        self::assertSame([$c['id'], $a['id'], $b['id']], array_column($left, 'id'));
    }

    public function testRepositoryReorderRejectsDuplicateMixedIncompleteAndMalformedOrders(): void
    {
        $a = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => 'a']]);
        $b = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 1, 'settings' => ['text' => 'b']]);
        $r = $this->blocks->create(['location' => 'right', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => 'r']]);

        $invalid = [
            [$a['id'], $a['id']],               // duplicate
            [$a['id'], $r['id']],               // mixed location
            [$a['id']],                         // incomplete
            [$a['id'], 'not-a-valid-id'],       // malformed id
        ];

        $rejected = 0;
        foreach ($invalid as $order) {
            try {
                $this->blocks->reorder('left', $order);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalid), $rejected);

        $left = $this->blocks->listByLocation('left');
        self::assertSame([$a['id'], $b['id']], array_column($left, 'id'));
    }

    public function testReorderEndpointRequiresAuthenticationAndCsrf(): void
    {
        $anonymous = $this->controller->reorder($this->post(['location' => 'left', 'order' => '[]']));
        self::assertSame(302, $anonymous->status());

        $this->authenticate();
        $get = $this->controller->reorder($this->get());
        self::assertSame(405, $get->status());

        $badCsrf = $this->controller->reorder($this->post(['location' => 'left', 'order' => '[]', '_csrf' => 'invalid']));
        self::assertSame(400, $badCsrf->status());
    }

    public function testReorderEndpointPersistsOrderViaPost(): void
    {
        $a = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => 'a']]);
        $b = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 1, 'settings' => ['text' => 'b']]);
        $c = $this->blocks->create(['location' => 'left', 'type' => 'copyright', 'position' => 2, 'settings' => ['text' => 'c']]);

        $this->authenticate();
        $token = $this->csrf->token();
        $response = $this->controller->reorder($this->post([
            'location' => 'left',
            'order' => json_encode([$c['id'], $a['id'], $b['id']], JSON_THROW_ON_ERROR),
            '_csrf' => $token,
        ]));
        self::assertSame(303, $response->status());

        $left = $this->blocks->listByLocation('left');
        self::assertSame([$c['id'], $a['id'], $b['id']], array_column($left, 'id'));
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
