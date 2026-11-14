<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Segara\Admin\AuthController;
use Segara\Admin\DashboardController;
use Segara\Auth\AdminRepository;
use Segara\Auth\CaptchaVerifier;
use Segara\Auth\Csrf;
use Segara\Auth\LoginThrottle;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Contact\MessageRepository;
use Segara\Http\Request;
use Segara\Http\SecurityHeaders;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class LoginTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private LoginThrottle $throttle;
    private int $now = 1700000000;
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
        $this->admins->create('admin', 'Administrator', self::PASSWORD);
        $this->config = $this->config();
        $this->session = new Session($this->config, $this->storePath . '/sessions', fn(): int => $this->now);
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->throttle = new LoginThrottle($this->pdo, fn(): int => $this->now);
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

    public function testAnonymousAdministrationRedirectsToLoginWithoutThirdPartyRequests(): void
    {
        $response = $this->dashboardController()->index();

        self::assertSame(302, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
        self::assertSame(SecurityHeaders::CSP, $response->headers()['Content-Security-Policy']);
        self::assertSame('', $response->body());
    }

    public function testBadCsrfIsRejectedBeforeCaptchaCredentialsOrThrottle(): void
    {
        $calls = 0;
        $controller = $this->controller($this->config('recaptcha'), $this->transport($calls, '{"success":true}'));

        $response = $controller->login($this->post([
            Csrf::FORM_FIELD => 'invalid',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'g-recaptcha-response' => 'token',
        ]));

        self::assertSame(400, $response->status());
        self::assertStringContainsString('Invalid request.', $response->body());
        self::assertSame(0, $calls);
        self::assertNull($this->session->adminId());
        self::assertSame(0, $this->throttleCount());
    }

    public function testUnknownUsernameAndBadPasswordHaveIdenticalGenericResponses(): void
    {
        $controller = $this->controller();
        $token = $this->csrf->token();

        $unknown = $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'missing',
            'password' => 'incorrect password',
        ]));
        $badPassword = $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'admin',
            'password' => 'incorrect password',
        ]));

        self::assertSame(401, $unknown->status());
        self::assertSame($unknown->status(), $badPassword->status());
        self::assertSame($unknown->body(), $badPassword->body());
        self::assertSame(1, substr_count($unknown->body(), 'Invalid credentials.'));
        self::assertStringNotContainsString('missing', $unknown->body());
        self::assertStringNotContainsString('incorrect password', $unknown->body());
    }

    public function testFiveFailuresThrottleForFiveMinutesButNeverPermanently(): void
    {
        $controller = $this->controller();
        $token = $this->csrf->token();
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $response = $controller->login($this->post([
                Csrf::FORM_FIELD => $token,
                'username' => 'admin',
                'password' => 'incorrect password',
            ]));
            self::assertSame(401, $response->status());
        }

        $fifth = $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'admin',
            'password' => 'incorrect password',
        ]));
        self::assertSame(429, $fifth->status());
        self::assertStringContainsString('Too many attempts. Try again later.', $fifth->body());

        $this->now += 299;
        self::assertSame(429, $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]))->status());
        self::assertNull($this->session->adminId());

        $this->now += 1;
        $success = $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]));
        self::assertSame(302, $success->status());
        self::assertNotNull($this->session->adminId());
        self::assertSame(0, $this->throttleCount());
    }

    public function testFailureWindowExpiresAndExpiredRowsArePruned(): void
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->throttle->recordFailure('admin');
        }
        $this->throttle->recordFailure('expired');
        $this->now += 901;

        self::assertSame(0, $this->throttle->recordFailure('admin'));
        self::assertSame(1, $this->throttleFailures('admin'));
        self::assertSame(1, $this->throttleCount(), 'The expired throttle row must be pruned on a write.');
    }

    public function testThrottleStorageIsBoundedToFiveHundredEntries(): void
    {
        for ($entry = 0; $entry < 501; ++$entry) {
            $this->throttle->recordFailure('identity-' . $entry);
        }

        self::assertSame(500, $this->throttleCount());
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->throttle->recordFailure('identity-500');
        }
        self::assertGreaterThan(0, $this->throttle->retryAfter('identity-500'));
        self::assertSame(500, $this->throttleCount());
    }

    public function testSuccessfulLoginRegeneratesSessionResetsFailuresAndStoresCurrentEpoch(): void
    {
        $controller = $this->controller();
        $initialToken = $this->csrf->token();
        $anonymousId = $this->session->id();
        $controller->login($this->post([
            Csrf::FORM_FIELD => $initialToken,
            'username' => 'admin',
            'password' => 'incorrect password',
        ]));
        self::assertSame(1, $this->throttleCount());

        $response = $controller->login($this->post([
            Csrf::FORM_FIELD => $initialToken,
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/admin.php', $response->headers()['Location']);
        self::assertNotSame($anonymousId, $this->session->id());
        self::assertSame(0, $this->throttleCount());
        $admin = $this->admins->findByUsername('admin');
        self::assertNotNull($admin);
        self::assertTrue($this->session->hasValidAuthentication($admin['auth_epoch']));
        self::assertFalse($this->csrf->validate($this->post([Csrf::FORM_FIELD => $initialToken])));
    }

    public function testSubsequentAdminCheckRejectsStaleAuthenticationEpoch(): void
    {
        $controller = $this->controller();
        $token = $this->csrf->token();
        self::assertSame(302, $controller->login($this->post([
            Csrf::FORM_FIELD => $token,
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]))->status());
        $admin = $this->admins->findByUsername('admin');
        self::assertNotNull($admin);
        $this->admins->updatePassword($admin['id'], 'a new correct password');

        $response = $this->dashboardController()->index();

        self::assertSame(302, $response->status());
        self::assertSame('/admin.php?action=login', $response->headers()['Location']);
        self::assertNull($this->session->adminId());
    }

    public function testInvalidCaptchaFailsClosedAndUsesSecretOnlyInPostBody(): void
    {
        $calls = 0;
        $capturedUrl = '';
        $capturedFields = [];
        $capturedOptions = [];
        $transport = function (string $url, array $fields, array $options) use (&$calls, &$capturedUrl, &$capturedFields, &$capturedOptions): string {
            ++$calls;
            $capturedUrl = $url;
            $capturedFields = $fields;
            $capturedOptions = $options;
            return '{"success":false}';
        };
        $controller = $this->controller($this->config('turnstile'), $transport);

        $response = $controller->login($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'username' => 'admin',
            'password' => self::PASSWORD,
            'cf-turnstile-response' => 'invalid-token',
        ]));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Invalid credentials.', $response->body());
        self::assertSame(1, $calls);
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $capturedUrl);
        self::assertSame('invalid-token', $capturedFields['response']);
        self::assertSame('test-secret', $capturedFields['secret']);
        self::assertStringNotContainsString('test-secret', $capturedUrl);
        self::assertSame(5, $capturedOptions['timeout']);
        self::assertFalse($capturedOptions['follow_redirects']);
        self::assertTrue($capturedOptions['verify_peer']);
        self::assertTrue($capturedOptions['verify_peer_name']);
        self::assertNull($this->session->adminId());
    }

    public function testMissingOrMalformedCaptchaDoesNotCallTransport(): void
    {
        $calls = 0;
        $controller = $this->controller($this->config('recaptcha'), $this->transport($calls));
        foreach ([null, '', ['nested'], str_repeat('x', 4097)] as $captcha) {
            $response = $controller->login($this->post([
                Csrf::FORM_FIELD => $this->csrf->token(),
                'username' => 'admin',
                'password' => self::PASSWORD,
                'g-recaptcha-response' => $captcha,
            ]));
            self::assertSame(401, $response->status());
        }

        self::assertSame(0, $calls);
        self::assertNull($this->session->adminId());
    }

    /** @param 'recaptcha'|'turnstile' $provider */
    #[DataProvider('captchaProviders')]
    public function testEnabledCaptchaUsesOnlyProviderAssetsAndScopedCspOnLogin(string $provider, string $script, string $frame): void
    {
        $calls = 0;
        $controller = $this->controller($this->config($provider), $this->transport($calls));
        $response = $controller->login($this->get());
        $csp = $response->headers()['Content-Security-Policy'];

        self::assertSame(200, $response->status());
        self::assertSame(0, $calls, 'Rendering login must not perform verification requests.');
        self::assertSame(1, substr_count($response->body(), $script));
        self::assertStringContainsString('data-sitekey="test-site-key"', $response->body());
        self::assertStringContainsString("script-src 'self' " . $script, $csp);
        self::assertStringContainsString('frame-src ' . $frame, $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('test-secret', $response->body());
    }

    /** @return iterable<string, array{'recaptcha'|'turnstile', string, string}> */
    public static function captchaProviders(): iterable
    {
        yield 'recaptcha' => ['recaptcha', 'https://www.google.com/recaptcha/api.js', 'https://www.google.com'];
        yield 'turnstile' => ['turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'https://challenges.cloudflare.com'];
    }

    public function testCaptchaCspDoesNotLeakToAuthenticatedDashboard(): void
    {
        $calls = 0;
        $controller = $this->controller($this->config('recaptcha'), $this->transport($calls));
        self::assertSame(302, $controller->login($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'username' => 'admin',
            'password' => self::PASSWORD,
            'g-recaptcha-response' => 'valid-token',
        ]))->status());

        $dashboard = $this->dashboardController($this->config('recaptcha'))->index();
        self::assertSame(200, $dashboard->status());
        self::assertSame(SecurityHeaders::CSP, $dashboard->headers()['Content-Security-Policy']);
    }

    public function testAuthenticatedDashboardRendersControlPanel(): void
    {
        $controller = $this->controller();
        self::assertSame(302, $controller->login($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]))->status());

        $dashboard = $this->dashboardController()->index();

        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Administration', $dashboard->body());
        self::assertStringContainsString('>Pages<', $dashboard->body());
        self::assertStringContainsString('>Media<', $dashboard->body());
        self::assertStringContainsString('>Settings<', $dashboard->body());
        self::assertStringContainsString('>Menu<', $dashboard->body());
        self::assertStringContainsString('>Admin<', $dashboard->body());
        self::assertStringContainsString('Recently updated pages', $dashboard->body());
        self::assertStringContainsString('Signed in as', $dashboard->body());
    }

    public function testDisabledCaptchaMakesNoRequestsAndAddsNoThirdPartyAssetsOrCsp(): void
    {
        $calls = 0;
        $controller = $this->controller($this->config(), $this->transport($calls));
        $response = $controller->login($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(302, $response->status());
        self::assertSame(0, $calls);
        self::assertSame(SecurityHeaders::CSP, $this->controller()->login($this->get())->headers()['Content-Security-Policy']);
        self::assertStringNotContainsString('google.com', $this->controller()->login($this->get())->body());
        self::assertStringNotContainsString('cloudflare.com', $this->controller()->login($this->get())->body());
    }

    /** @param 'recaptcha'|'turnstile' $provider */
    #[DataProvider('captchaVerifierProviders')]
    public function testCaptchaVerifierUsesFixedOfficialEndpointAndAcceptsOnlySuccessTrue(string $provider): void
    {
        $calls = 0;
        $verifier = new CaptchaVerifier($this->config($provider), $this->transport($calls, '{"success":true}'));

        self::assertTrue($verifier->verify('valid-token'));
        self::assertSame(1, $calls);

        $invalid = new CaptchaVerifier($this->config($provider), $this->transport($calls, '{"success":"true"}'));
        self::assertFalse($invalid->verify('valid-token'));
    }

    /** @return iterable<string, array{'recaptcha'|'turnstile'}> */
    public static function captchaVerifierProviders(): iterable
    {
        yield 'recaptcha' => ['recaptcha'];
        yield 'turnstile' => ['turnstile'];
    }

    public function testCaptchaTransportFailuresAndMalformedJsonFailClosed(): void
    {
        foreach (['not-json', '{"success":false}', ''] as $body) {
            $verifier = new CaptchaVerifier($this->config('recaptcha'), static fn(): string => $body);
            self::assertFalse($verifier->verify('token'));
        }
        $verifier = new CaptchaVerifier($this->config('recaptcha'), static function (): string {
            throw new RuntimeException('simulated transport failure');
        });
        self::assertFalse($verifier->verify('token'));
    }

    /** @param 'recaptcha'|'turnstile'|null $provider */
    private function config(?string $provider = null): Config
    {
        return Config::fromArray([
            'base_url' => 'https://example.test',
            'environment' => 'test',
            'captcha' => $provider === null ? [] : [
                'provider' => $provider,
                'site_key' => 'test-site-key',
                'secret_key' => 'test-secret',
            ],
        ]);
    }

    /**
     * @param null|callable(string, array<string, string>, array<string, bool|int>): string $transport
     */
    private function controller(?Config $config = null, ?callable $transport = null): AuthController
    {
        $config ??= $this->config;
        $verifier = new CaptchaVerifier($config, $transport ?? static fn(): string => '{"success":false}');

        return new AuthController($config, $this->admins, $this->throttle, $this->session, $this->csrf, $verifier);
    }

    private function dashboardController(?Config $config = null): DashboardController
    {
        return new DashboardController(
            $config ?? $this->config,
            new PageRepository($this->pdo),
            new MediaRepository($this->pdo),
            new MessageRepository($this->pdo),
            $this->admins,
            $this->session,
            $this->csrf,
            new \Segara\Settings\SettingsRepository($this->pdo),
        );
    }

    /**
     * @param int $calls
     * @return Closure(string, array<string, string>, array<string, bool|int>): string
     */
    private function transport(int &$calls, string $body = '{"success":true}'): Closure
    {
        return static function () use (&$calls, $body): string {
            ++$calls;
            return $body;
        };
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

    private function throttleCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM throttle');
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    private function throttleFailures(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT failures FROM throttle WHERE key = :key');
        $statement->execute(['key' => hash('sha256', $key)]);
        return (int) $statement->fetchColumn();
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
