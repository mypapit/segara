<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use RuntimeException;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Tests\Support\TestCase;

final class HttpSecurityTest extends TestCase
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

    public function testRequestAllowlistsMethodsAndRejectsOversizedBodiesBeforeUse(): void
    {
        $request = Request::fromArrays(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '4'],
            ['title' => 'test'],
            [],
            'test',
            4,
        );

        self::assertSame('POST', $request->method());
        self::assertTrue($request->isMutation());
        self::assertSame('test', $request->post('title'));
        self::assertSame('test', $request->body());

        $this->expectException(InvalidArgumentException::class);
        Request::fromArrays(['REQUEST_METHOD' => 'DELETE']);
    }

    public function testRequestRejectsDeclaredAndActualBodyOverflow(): void
    {
        foreach ([
            ['CONTENT_LENGTH' => '5'],
            ['CONTENT_LENGTH' => 'invalid'],
        ] as $server) {
            try {
                Request::fromArrays(['REQUEST_METHOD' => 'POST'] + $server, [], [], 'test', 4);
                self::fail('Oversized or malformed request framing was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Request::fromArrays(['REQUEST_METHOD' => 'POST'], [], [], '12345', 4);
    }

    public function testResponseCarriesStatusHeadersAndBodyAndSensitiveResponsesAreNotStored(): void
    {
        $response = new Response(201, ['Content-Type' => 'text/plain'], 'created');
        self::assertSame(201, $response->status());
        self::assertSame('created', $response->body());
        self::assertSame('text/plain', $response->headers()['Content-Type']);

        $sensitive = $response->asSensitive();
        self::assertSame('no-store, private', $sensitive->headers()['Cache-Control']);
        self::assertSame('no-cache', $sensitive->headers()['Pragma']);
        self::assertSame('0', $sensitive->headers()['Expires']);
    }

    public function testResponseRejectsHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response(200, ["X-Test" => "safe\r\nSet-Cookie: stolen=1"]);
    }

    public function testSecurityHeadersUseOnlyLocalAssetsAndConfiguredHttpsControlsHsts(): void
    {
        $headers = SecurityHeaders::headers(false);
        self::assertSame(
            "default-src 'self'; img-src 'self' data:; style-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            $headers['Content-Security-Policy'],
        );
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
        self::assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::headers(true));
        self::assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::forConfig($this->config('https://example.test')));
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::forConfig($this->config('http://example.test')));
    }

    public function testSessionUsesStrictCookieOnlySettingsAndPrivateSavePath(): void
    {
        $session = new Session($this->config('https://example.test'), $this->storePath . '/sessions');
        $session->start();

        self::assertSame('1', ini_get('session.use_cookies'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('0', ini_get('session.use_trans_sid'));
        self::assertSame($this->storePath . '/sessions', session_save_path());
        $cookie = session_get_cookie_params();
        self::assertTrue($cookie['secure']);
        self::assertTrue($cookie['httponly']);
        self::assertSame('Lax', $cookie['samesite']);
        self::assertSame('/', $cookie['path']);
    }

    public function testSecureCookieFollowsConfiguredOriginRatherThanForwardedHeaders(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        try {
            $session = new Session($this->config('http://example.test'), $this->storePath . '/sessions');
            $session->start();
            self::assertFalse(session_get_cookie_params()['secure']);
        } finally {
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        }
    }

    public function testSessionRegeneratesOnLoginPrivilegeChangeAndLogout(): void
    {
        $session = new Session($this->config(), $this->storePath . '/sessions');
        $session->start();
        $anonymousId = $session->id();
        $session->login('admin-1', 3);
        $loginId = $session->id();
        self::assertNotSame($anonymousId, $loginId);
        self::assertTrue($session->hasValidAuthentication(3));
        self::assertSame('admin-1', $session->adminId());

        $session->privilegeChanged();
        self::assertNotSame($loginId, $session->id());
        $privilegeId = $session->id();
        $session->logout();
        self::assertNotSame($privilegeId, $session->id());
        self::assertFalse($session->hasValidAuthentication(3));
        self::assertNull($session->adminId());
    }

    public function testIdleAndAbsoluteTimeoutsInvalidateAuthentication(): void
    {
        $now = 1000;
        $config = $this->config('https://example.test', 10, 20);
        $session = new Session($config, $this->storePath . '/sessions', static function () use (&$now): int {
            return $now;
        });
        $session->start();
        $session->login('admin-1', 1);
        $now += 11;
        self::assertFalse($session->hasValidAuthentication(1));
        self::assertNull($session->adminId());

        $session->login('admin-1', 1);
        $now += 5;
        self::assertTrue($session->hasValidAuthentication(1));
        $now += 16;
        self::assertFalse($session->hasValidAuthentication(1), 'Absolute lifetime must survive activity refreshes.');
    }

    public function testAuthEpochMismatchInvalidatesExistingSession(): void
    {
        $session = new Session($this->config(), $this->storePath . '/sessions');
        $session->start();
        $session->login('admin-1', 4);
        $oldId = $session->id();

        self::assertFalse($session->hasValidAuthentication(5));
        self::assertNotSame($oldId, $session->id());
        self::assertNull($session->adminId());
    }

    private function config(string $baseUrl = 'https://example.test', int $idle = 1800, int $absolute = 28800): Config
    {
        return Config::fromArray([
            'base_url' => $baseUrl,
            'environment' => 'test',
            'session' => ['idle_timeout' => $idle, 'absolute_timeout' => $absolute],
        ]);
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
