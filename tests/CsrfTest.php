<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Tests\Support\TestCase;

final class CsrfTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalIni = [];

    /** @var array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string} */
    private array $originalCookieParams;

    private string $originalSessionName = '';
    private string $originalSavePath = '';

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
    }

    protected function tearDown(): void
    {
        $this->closeSession();
        foreach ($this->originalIni as $key => $value) {
            if ($value !== false) {
                ini_set($key, $value);
            }
        }
        $this->restoreCookieParams();
        session_name($this->originalSessionName);
        session_save_path($this->originalSavePath);
        parent::tearDown();
    }

    public function testTokenContains32RandomBytesAndValidatesOnlyPostBodyTokens(): void
    {
        [$session, $csrf] = $this->services();
        $token = $csrf->token();
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, $csrf->token());

        $valid = $this->request('POST', [Csrf::FORM_FIELD => $token]);
        self::assertTrue($csrf->validate($valid));
        self::assertFalse($csrf->validate($this->request('POST', [Csrf::FORM_FIELD => str_repeat('0', 64)])));
        self::assertFalse($csrf->validate($this->request('POST')));
        self::assertFalse($csrf->validate($this->request('POST', [Csrf::FORM_FIELD => [$token]])));
        self::assertFalse($csrf->validate($this->request('GET', [Csrf::FORM_FIELD => $token])));
        self::assertSame('admin-1', $session->adminId(), 'Rejected GET must not mutate authentication state.');
    }

    public function testEveryMutationApiRejectsGetAndBadTokens(): void
    {
        [, $csrf] = $this->services();
        foreach ([
            $this->request('GET'),
            $this->request('POST'),
            $this->request('POST', [Csrf::FORM_FIELD => 'bad']),
        ] as $request) {
            try {
                $csrf->requireValid($request);
                self::fail('A mutation without a valid POST CSRF token was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRegenerationExpiresPreviousTokenOnLoginPrivilegeChangeAndLogout(): void
    {
        [$session, $csrf] = $this->services();
        $initial = $csrf->token();

        $session->login('admin-2', 1);
        $afterLogin = $csrf->token();
        self::assertNotSame($initial, $afterLogin);
        self::assertFalse($csrf->validate($this->request('POST', [Csrf::FORM_FIELD => $initial])));

        $session->privilegeChanged();
        $afterPrivilegeChange = $csrf->token();
        self::assertNotSame($afterLogin, $afterPrivilegeChange);
        self::assertFalse($csrf->validate($this->request('POST', [Csrf::FORM_FIELD => $afterLogin])));

        $session->logout();
        $afterLogout = $csrf->token();
        self::assertNotSame($afterPrivilegeChange, $afterLogout);
        self::assertFalse($csrf->validate($this->request('POST', [Csrf::FORM_FIELD => $afterPrivilegeChange])));
    }

    /** @return array{Session, Csrf} */
    private function services(): array
    {
        $config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $session = new Session($config, $this->storePath . '/sessions');
        $session->start();
        $session->login('admin-1', 1);

        return [$session, new Csrf($session)];
    }

    /** @param array<string, mixed> $post */
    private function request(string $method, array $post = []): Request
    {
        return Request::fromArrays(['REQUEST_METHOD' => $method], $post);
    }

    private function restoreCookieParams(): void
    {
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
            return;
        }

        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
        ]);
        ini_set('session.cookie_samesite', $sameSite);
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
