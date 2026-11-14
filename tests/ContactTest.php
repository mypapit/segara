<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\MessageController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Contact\ContactController;
use Segara\Contact\MessageRepository;
use Segara\Http\Request;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class ContactTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private MessageRepository $messages;
    private ContactController $contact;
    private MessageController $inbox;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
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
        $this->messages = new MessageRepository($this->pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->admins = new AdminRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->contact = new ContactController($this->messages, $this->session, $this->csrf, $this->pdo);
        $this->inbox = new MessageController($this->config, $this->messages, $this->admins, $this->session, $this->csrf);
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

    public function testFormRendersFieldsHoneypotAndCsrf(): void
    {
        $html = $this->contact->form($this->get());
        foreach (['name="name"', 'name="email"', 'name="subject"', 'name="message"', 'name="website"', 'name="_csrf"'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testValidSubmissionStoresMessageAndReturnsTruthfulResponse(): void
    {
        $token = $this->csrf->token();
        $html = $this->contact->form($this->post($this->valid() + ['_csrf' => $token]));

        self::assertStringContainsString('Message saved', $html);
        $all = $this->messages->list();
        self::assertCount(1, $all);
        self::assertSame('Alice', $all[0]['name']);
        self::assertSame('Hello subject', $all[0]['subject']);
        self::assertSame(0, $all[0]['is_read']);
    }

    public function testInvalidEmailAndEmptyBodyAreRejected(): void
    {
        $token = $this->csrf->token();

        $html = $this->contact->form($this->post($this->valid(['email' => 'not-an-email']) + ['_csrf' => $token]));
        self::assertStringContainsString('valid email', $html);
        self::assertSame([], $this->messages->list());

        $html = $this->contact->form($this->post($this->valid(['message' => '']) + ['_csrf' => $token]));
        self::assertStringNotContainsString('Message saved', $html);
        self::assertSame([], $this->messages->list());
    }

    public function testHoneypotDropsSubmissionWithoutRevealingIt(): void
    {
        $token = $this->csrf->token();
        $html = $this->contact->form($this->post($this->valid() + ['_csrf' => $token, 'website' => 'http://bot.example']));

        self::assertStringNotContainsString('Message saved', $html);
        self::assertSame([], $this->messages->list());
    }

    public function testBadCsrfRejectsSubmission(): void
    {
        $html = $this->contact->form($this->post($this->valid() + ['_csrf' => 'wrong-token']));
        self::assertStringContainsString('expired', $html);
        self::assertSame([], $this->messages->list());
    }

    public function testAdminInboxEscapesStoredContent(): void
    {
        $this->messages->create([
            'name' => '<script>alert(1)</script>', 'email' => 'x@example.com',
            'subject' => 'Hi', 'body' => '<b>bold</b> & raw',
        ]);
        $this->authenticate();

        $html = $this->inbox->index($this->get())->body();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    public function testAdminInboxRequiresAuthAndSupportsReadAndDelete(): void
    {
        self::assertSame(302, $this->inbox->index($this->get())->status());
        self::assertSame(302, $this->inbox->delete($this->post(['message_id' => 'x']))->status());

        $message = $this->messages->create(['name' => 'A', 'email' => 'a@b.com', 'subject' => 'S', 'body' => 'B']);
        $this->authenticate();
        $token = $this->csrf->token();

        self::assertStringContainsString('S', $this->inbox->index($this->get())->body());

        self::assertSame(303, $this->inbox->markRead($this->post(['message_id' => $message['id'], '_csrf' => $token]))->status());
        $read = $this->messages->getById($message['id']);
        self::assertNotNull($read);
        self::assertSame(1, $read['is_read']);

        self::assertSame(303, $this->inbox->delete($this->post(['message_id' => $message['id'], '_csrf' => $token]))->status());
        self::assertSame([], $this->messages->list());
    }

    public function testThrottleBoundsSubmissions(): void
    {
        $key = 'contact:' . $this->session->id();
        $now = time();
        $this->pdo->prepare(
            'INSERT INTO throttle (key, failures, window_started, retry_after, updated_at) VALUES (:key, 10, :now, :retry, :updated)'
        )->execute(['key' => $key, 'now' => $now, 'retry' => $now + 900, 'updated' => gmdate('Y-m-d\TH:i:s\Z')]);

        $token = $this->csrf->token();
        $html = $this->contact->form($this->post($this->valid() + ['_csrf' => $token]));

        self::assertStringContainsString('Too many messages', $html);
        self::assertSame([], $this->messages->list());
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'subject' => 'Hello subject',
            'message' => 'Hello body',
        ];
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
