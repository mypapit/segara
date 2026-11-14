<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\SettingsController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class SettingsTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private SettingsRepository $settings;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private SettingsController $controller;
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
        $this->settings = new SettingsRepository($this->pdo);
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->admins = new AdminRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new SettingsController(
            $this->config,
            $this->settings,
            new PageRepository($this->pdo),
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

    public function testRepositoryPersistsUpsertsAndGuardsKeys(): void
    {
        $this->settings->setMany(['site_name' => 'My Site', 'currency' => 'USD']);
        self::assertSame('My Site', $this->settings->get('site_name'));
        self::assertSame('USD', $this->settings->get('currency'));
        self::assertNull($this->settings->get('locale'));

        $this->settings->setMany(['site_name' => 'Renamed']);
        self::assertSame('Renamed', $this->settings->get('site_name'));
        self::assertSame('USD', $this->settings->get('currency'));

        try {
            $this->settings->setMany(['secret' => 'value']);
            self::fail('Expected an unknown key to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    public function testSettingsEndpointsRequireAuthenticationAndCsrf(): void
    {
        self::assertSame(302, $this->controller->index($this->get())->status());
        self::assertSame(302, $this->controller->update($this->post(['site_name' => 'X']))->status());

        $this->authenticate();
        self::assertSame(405, $this->controller->update($this->get())->status());
        self::assertSame(400, $this->controller->update($this->post(['site_name' => 'X', '_csrf' => 'bad']))->status());
    }

    public function testSettingsPageRendersThemePickerAndPostsToUpdateEndpoint(): void
    {
        $this->authenticate();

        $response = $this->controller->index($this->get());

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('action="/admin.php?action=settings-update"', $body);
        // The picker renders one card per available theme, each with a preview
        // that mirrors that theme's real column layout.
        // Count the card element itself: the BEM children share the prefix,
        // so 'class="theme-card' also matches theme-card__flag, __body, __name…
        self::assertSame(6, substr_count($body, '<label class="theme-card'));
        self::assertStringContainsString('name="theme"', $body);
        self::assertStringContainsString('theme-preview--aubergine', $body);
        self::assertStringContainsString('One sidebar', $body);
    }

    public function testSettingsUpdatePersistsValidValues(): void
    {
        $page = (new PageRepository($this->pdo))->create(['title' => 'Home', 'state' => 'published', 'author_name' => 'Admin']);
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara Site',
            'site_description' => 'A small site',
            'locale' => 'ms_MY',
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
            'theme' => 'editorial',
            'homepage_id' => $page['id'],
            'fuzzy_dates' => '1',
            '_csrf' => $token,
        ]));
        self::assertSame(303, $response->status());

        self::assertSame('Segara Site', $this->settings->get('site_name'));
        self::assertSame('ms_MY', $this->settings->get('locale'));
        self::assertSame('editorial', $this->settings->get('theme'));
        self::assertSame('1', $this->settings->get('fuzzy_dates'));
        self::assertSame($page['id'], $this->settings->get('homepage_id'));
    }

    /**
     * The screen must show what is stored. `values()` used to put the defaults on
     * the left of the array union, so every saved value was masked by its default
     * and the form re-rendered "Segara" after every successful save.
     */
    public function testTheSettingsScreenShowsTheStoredValuesNotTheDefaults(): void
    {
        $this->settings->setMany([
            'site_name' => 'Mobile Mypapit Policy Site',
            'site_description' => 'Notes from the field',
            'locale' => 'ms_MY',
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'SGD',
        ]);
        $this->authenticate();

        $body = $this->controller->index($this->get())->body();

        self::assertStringContainsString('value="Mobile Mypapit Policy Site"', $body, 'the site name input shows the stored name');
        self::assertStringContainsString('Notes from the field', $body, 'the description shows the stored text');
        self::assertStringContainsString('value="ms_MY"', $body);
        self::assertStringContainsString('value="Asia/Kuala_Lumpur"', $body);
        self::assertStringContainsString('value="SGD"', $body);
    }

    // The owner asked for a tagline in Settings, directly under the site name.
    public function testTheTaglineFieldSitsBetweenTheSiteNameAndTheDescription(): void
    {
        $this->authenticate();

        $body = $this->controller->index($this->get())->body();

        $name = strpos($body, 'id="site-name"');
        $tagline = strpos($body, 'id="site-tagline"');
        $description = strpos($body, 'id="site-description"');
        self::assertIsInt($name);
        self::assertIsInt($tagline);
        self::assertIsInt($description);
        self::assertLessThan($tagline, $name, 'the tagline input follows the site name');
        self::assertLessThan($description, $tagline, 'the tagline input precedes the description');
        self::assertStringContainsString('name="site_tagline"', $body);
        self::assertMatchesRegularExpression('/<input id="site-tagline"[^>]*value=""/', $body, 'optional, so it renders empty');
        self::assertMatchesRegularExpression('/<input id="site-tagline"(?![^>]*\brequired\b)/', $body, 'the tagline is optional');
    }

    public function testTheSavedTaglineIsShownOnTheSettingsScreen(): void
    {
        $this->settings->setMany(['site_name' => 'Segara', 'site_tagline' => 'Notes on teaching and research']);
        $this->authenticate();

        $body = $this->controller->index($this->get())->body();

        self::assertStringContainsString('value="Notes on teaching and research"', $body);
    }

    public function testSettingsUpdatePersistsTheTagline(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara',
            'site_tagline' => 'Notes on teaching and research',
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            '_csrf' => $token,
        ]));

        self::assertSame(303, $response->status());
        self::assertSame('Notes on teaching and research', $this->settings->get('site_tagline'));
    }

    public function testSettingsUpdateRejectsAnOverlongTaglineAndKeepsTheStoredOne(): void
    {
        $this->settings->setMany(['site_tagline' => 'Short and sweet']);
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara',
            'site_tagline' => str_repeat('a', 301),
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            '_csrf' => $token,
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('tagline', $response->body());
        self::assertSame('Short and sweet', $this->settings->get('site_tagline'), 'a rejected save must not touch the store');
    }

    public function testASavedSiteNameIsStillShownAfterTheRedirect(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Renamed by the owner',
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            '_csrf' => $token,
        ]));
        self::assertSame(303, $response->status());

        // The redirect lands on the settings screen; that is what the owner sees.
        $body = $this->controller->index($this->get())->body();
        self::assertStringContainsString('value="Renamed by the owner"', $body);
        self::assertStringNotContainsString('value="Segara"', $body);
    }

    public function testThePageDateFormatDefaultsToFull(): void
    {
        $this->authenticate();

        $body = $this->controller->index($this->get())->body();

        self::assertStringContainsString('name="page_date_format"', $body);
        self::assertMatchesRegularExpression('/<option value="full"\s+selected/', $body);
        self::assertStringContainsString('value="F j, Y (l)"', $body);
        self::assertStringNotContainsString('name="fuzzy_dates"', $body, 'the checkbox is superseded by the select');
        self::assertStringContainsString('Example:', $body);
    }

    public function testSavingACustomPatternPersistsAndReappears(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara',
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            'page_date_format' => 'custom',
            'page_date_pattern' => 'j F Y',
            '_csrf' => $token,
        ]));
        self::assertSame(303, $response->status());
        self::assertSame('custom', $this->settings->get('page_date_format'));
        self::assertSame('j F Y', $this->settings->get('page_date_pattern'));

        $body = $this->controller->index($this->get())->body();
        self::assertStringContainsString('value="j F Y"', $body);
    }

    public function testAnUnknownDateFormatIsRejected(): void
    {
        $this->settings->setMany(['page_date_format' => 'plain']);
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara',
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            'page_date_format' => 'shouty',
            '_csrf' => $token,
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Date format', $response->body());
        self::assertSame('plain', $this->settings->get('page_date_format'), 'a rejected save does not touch the store');
    }

    public function testACustomModeRejectsAPatternWithNoDateToken(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();

        $response = $this->controller->update($this->post([
            'site_name' => 'Segara',
            'locale' => 'en',
            'timezone' => 'UTC',
            'currency' => 'MYR',
            'theme' => 'clarity',
            'page_date_format' => 'custom',
            'page_date_pattern' => 'hello',
            '_csrf' => $token,
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('date token', $response->body());
    }

    public function testSettingsUpdateRejectsInvalidValues(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();
        $base = ['site_name' => 'X', 'locale' => 'en', 'timezone' => 'UTC', 'currency' => 'MYR', 'theme' => 'clarity', '_csrf' => $token];

        $rejected = 0;
        foreach ([
            ['theme' => 'bogus'],
            ['timezone' => 'Mars/Nowhere'],
            ['currency' => 'USDD'],
            ['locale' => 'not a locale!!'],
            ['site_name' => ''],
        ] as $overrides) {
            $response = $this->controller->update($this->post(array_merge($base, $overrides)));
            if ($response->status() === 422) {
                $rejected++;
            }
        }
        self::assertSame(5, $rejected);
        self::assertSame([], $this->settings->all());
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
