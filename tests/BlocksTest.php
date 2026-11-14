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
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\BlockRepository;
use Segara\Config;
use Segara\Http\Request;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class BlocksTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private BlockRepository $blocks;
    private BlockRenderer $renderer;
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
        $this->renderer = new BlockRenderer($this->config, new MenuRepository($this->pdo));
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

    public function testRepositoryAcceptsAllBlockTypesAndOrdersByPositionWithinLocation(): void
    {
        $settings = [
            'menu' => ['menu_id' => str_repeat('a', 32)],
            'images' => ['image_ids' => [str_repeat('b', 32)]],
            'rss' => [],
            'social' => ['networks' => [['provider' => 'github', 'url' => 'https://github.com/x']]],
            'external-links' => ['links' => [['label' => 'Docs', 'url' => 'https://example.com']]],
            'quick-facts' => ['facts' => [['text' => 'Fact one']]],
            'custom-inline' => ['html' => '<strong>bold</strong>'],
            'copyright' => ['text' => '© 2026'],
            'address' => ['text' => '1 Main Street'],
            'blurb' => ['text' => 'About the author'],
        ];
        foreach ($settings as $type => $s) {
            $this->blocks->create(['location' => 'left', 'type' => $type, 'position' => 0, 'settings' => $s]);
        }

        $all = $this->blocks->listAll();
        self::assertCount(10, $all);

        $this->blocks->create(['location' => 'right', 'type' => 'copyright', 'position' => 10, 'settings' => ['text' => 'z']]);
        $this->blocks->create(['location' => 'right', 'type' => 'copyright', 'position' => 1, 'settings' => ['text' => 'a']]);
        $right = $this->blocks->listByLocation('right');
        $first = json_decode($right[0]['settings_json'], true);
        $second = json_decode($right[1]['settings_json'], true);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('a', $first['text']);
        self::assertSame('z', $second['text']);
    }

    public function testRepositorySanitizesCustomHtmlAndRejectsUnsafeUrlsAndProviders(): void
    {
        $block = $this->blocks->create([
            'location' => 'left', 'type' => 'custom-inline', 'position' => 0,
            'settings' => ['html' => '<p onclick="x()">Hi <script>alert(1)</script><pre>code</pre><custom>x</custom></p>'],
        ]);
        $settings = json_decode($block['settings_json'], true);
        self::assertIsArray($settings);
        $html = $settings['html'];
        self::assertIsString($html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<pre>', $html);
        self::assertStringNotContainsString('<custom>', $html);
        self::assertStringNotContainsString('onclick', $html);

        $invalid = [
            ['location' => 'left', 'type' => 'social', 'position' => 0, 'settings' => ['networks' => [['provider' => 'evil', 'url' => 'https://x.com']]]],
            ['location' => 'left', 'type' => 'social', 'position' => 0, 'settings' => ['networks' => [['provider' => 'github', 'url' => 'javascript:alert(1)']]]],
            ['location' => 'left', 'type' => 'external-links', 'position' => 0, 'settings' => ['links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]],
            ['location' => 'middle', 'type' => 'copyright', 'position' => 0, 'settings' => ['text' => 'x']],
            ['location' => 'left', 'type' => 'bogus', 'position' => 0, 'settings' => []],
        ];
        $rejected = 0;
        foreach ($invalid as $input) {
            try {
                $this->blocks->create($input);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalid), $rejected);
    }

    public function testRendererRendersAllTypesEscaped(): void
    {
        $block = $this->blocks->create(['location' => 'footer', 'type' => 'social', 'position' => 0, 'settings' => [
            'title' => 'Follow <us>', 'networks' => [['provider' => 'x', 'url' => 'https://x.com/foo']],
        ]]);
        $html = $this->renderer->render($block);
        self::assertStringContainsString('Follow &lt;us&gt;', $html);
        self::assertStringContainsString('icon-x', $html);
        self::assertStringContainsString('https://x.com/foo', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testMenuBlockRendersNavigation(): void
    {
        $menus = new MenuRepository($this->pdo);
        $pages = new PageRepository($this->pdo);
        $menu = $menus->createMenu('Main');
        $page = $pages->create(['title' => 'About', 'state' => 'published', 'author_name' => 'Admin']);
        $menus->addItem(['menu_id' => $menu['id'], 'label' => 'About Us', 'target_type' => 'page', 'page_id' => $page['id'], 'position' => 0]);

        $block = $this->blocks->create(['location' => 'left', 'type' => 'menu', 'position' => 0, 'settings' => ['menu_id' => $menu['id']]]);
        $html = $this->renderer->render($block);

        self::assertStringContainsString('About Us', $html);
        self::assertStringContainsString('/about', $html);
    }

    public function testBlockEndpointsRequireAuthenticationAndCsrf(): void
    {
        $anonymous = $this->controller->index($this->get());
        self::assertSame(302, $anonymous->status());

        $this->authenticate();
        $get = $this->controller->create($this->get());
        self::assertSame(405, $get->status());

        $badCsrf = $this->controller->create($this->post(['location' => 'left', 'type' => 'copyright', 'settings' => ['text' => 'x'], '_csrf' => 'invalid']));
        self::assertSame(400, $badCsrf->status());
        self::assertSame([], $this->blocks->listAll());
    }

    public function testControllerCreatesAndDeletesBlockViaPost(): void
    {
        $this->authenticate();
        $token = $this->csrf->token();

        $create = $this->controller->create($this->post([
            'location' => 'footer', 'type' => 'copyright', 'position' => '3',
            'settings' => ['text' => '© 2026 Example'], '_csrf' => $token,
        ]));
        self::assertSame(303, $create->status());
        $all = $this->blocks->listAll();
        self::assertCount(1, $all);
        self::assertSame('footer', $all[0]['location']);

        $unconfirmed = $this->controller->delete($this->post(['block_id' => $all[0]['id'], '_csrf' => $token]));
        self::assertSame(422, $unconfirmed->status(), 'deletion must be confirmed');
        self::assertCount(1, $this->blocks->listAll());

        $delete = $this->controller->delete($this->post([
            'block_id' => $all[0]['id'], 'confirmation' => 'delete', '_csrf' => $token,
        ]));
        self::assertSame(303, $delete->status());
        self::assertSame([], $this->blocks->listAll());
    }

    public function testCreateAppendsInsteadOfCollidingAtZero(): void
    {
        $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'A', 'bullet' => '']]]]);
        $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]]]);
        $this->blocks->create(['location' => 'footer', 'type' => 'address', 'settings' => ['text' => 'C']]);

        $positions = array_map(
            static fn(array $block): int => $block['position'],
            $this->blocks->listByLocation('left')
        );
        self::assertSame([0, 1], $positions, 'each new block takes the next slot');
    }

    public function testMovingABlockRenumbersBothRegions(): void
    {
        $a = $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'A', 'bullet' => '']]]]);
        $b = $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]]]);
        $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'C', 'bullet' => '']]]]);

        $this->blocks->update($b['id'], $b['revision'], [
            'location' => 'footer',
            'type' => 'quick-facts',
            'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]],
        ]);

        $left = array_map(static fn(array $block): int => $block['position'], $this->blocks->listByLocation('left'));
        self::assertSame([0, 1], $left, 'the source region closes its gap');
        $footer = $this->blocks->listByLocation('footer');
        self::assertCount(1, $footer);
        self::assertSame(0, $footer[0]['position']);
        self::assertNotSame($a['id'], $b['id']);
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

    public function testTheLocationAndTypeInTheUrlSeedTheNewBlockForm(): void
    {
        $this->authenticate();

        // The admin flow is a GET form that comes back as
        // ?action=blocks&location=left&type=images — the second step must show
        // the settings for that type, not the default one.
        $body = $this->controller->index($this->get(['location' => 'left', 'type' => 'images']))->body();

        // The images fieldset is only rendered for that type; the checkbox list
        // itself needs an uploaded image, so assert the type-specific heading.
        self::assertStringContainsString('id="bf-images-title"', $body);
        self::assertStringContainsString('<option value="images" selected>', $body);
        self::assertStringContainsString('<input type="hidden" name="type" value="images">', $body);
        self::assertStringNotContainsString('settings[facts][0][text]', $body);
    }

    public function testTheFooterLocationSeedsAFooterTypeNotTheLeftOne(): void
    {
        $this->authenticate();

        $body = $this->controller->index($this->get(['location' => 'footer']))->body();

        self::assertStringContainsString('<input type="hidden" name="location" value="footer">', $body);
        self::assertStringContainsString('<option value="footer" selected>', $body);
    }

    public function testTheTypeMenuOnlyOffersTypesAllowedInTheChosenLocation(): void
    {
        $this->authenticate();

        $body = $this->controller->index($this->get(['location' => 'left']))->body();

        // Copyright/address/blurb are footer-only, so offering them for a sidebar
        // produces a request the server then rejects.
        self::assertStringContainsString('<option value="quick-facts"', $body);
        self::assertStringNotContainsString('<option value="copyright"', $body);
        self::assertStringNotContainsString('<option value="blurb"', $body);
    }

    public function testAnUnknownTypeFallsBackToATypeThatFitsTheLocation(): void
    {
        $this->authenticate();

        $body = $this->controller->index($this->get(['location' => 'footer', 'type' => 'not-a-type']))->body();

        self::assertStringContainsString('<option value="footer" selected>', $body);
        self::assertMatchesRegularExpression('/<option value="(copyright|address|blurb|social|external-links|custom-inline)" selected>/', $body);
    }

    /**
     * The preview paints a real block from the public renderer, so it carries
     * the sprite <svg> but none of the theme's stylesheet. Without an explicit
     * size the icon falls back to the SVG default of 300x150 and swells the row.
     */
    public function testTheBlockPreviewSizesSpriteIcons(): void
    {
        $this->authenticate();
        $this->blocks->create([
            'location' => 'left',
            'type' => 'social',
            'position' => 0,
            'settings' => ['networks' => [['provider' => 'github', 'url' => 'https://github.com/x']]],
        ]);

        // The preview only paints a sprite icon when the controller has the
        // renderer, which is exactly how public/admin.php wires it.
        $controller = new BlockController($this->config, $this->blocks, $this->admins, $this->session, $this->csrf, $this->renderer);
        $body = $controller->index($this->get())->body();
        self::assertMatchesRegularExpression(
            '/class="block-preview".{0,300}?<svg class="icon"/s',
            $body,
            'the preview renders a real sprite icon'
        );

        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.block-preview :where\(svg\)\s*\{[^}]*width:\s*1\.15em/s',
            $css,
            'the preview gives its icons a width'
        );
        self::assertMatchesRegularExpression(
            '/\.block-preview :where\(svg\)\s*\{[^}]*height:\s*1\.15em/s',
            $css,
            'the preview gives its icons a height'
        );
        self::assertMatchesRegularExpression('/\.block-preview :where\(h2\)\s*\{[^}]*display:\s*inline/s', $css);
        self::assertMatchesRegularExpression('/\.block-preview :where\(ul\)\s*\{[^}]*display:\s*inline/s', $css);
    }

    public function testReorderSavesTheSubmittedOrder(): void
    {
        $a = $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'A', 'bullet' => '']]]]);
        $b = $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]]]);
        $c = $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'C', 'bullet' => '']]]]);
        $this->authenticate();

        $response = $this->controller->reorder($this->post([
            Csrf::FORM_FIELD => $this->csrf->token(),
            'location' => 'left',
            'order' => json_encode([$c['id'], $a['id'], $b['id']], JSON_THROW_ON_ERROR),
        ]));

        self::assertSame(303, $response->status());
        self::assertSame(
            [$c['id'], $a['id'], $b['id']],
            array_column($this->blocks->listByLocation('left'), 'id')
        );
    }

    public function testTheArrowsCarryTheirOwnOrderWithoutNestedForms(): void
    {
        $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'A', 'bullet' => '']]]]);
        $this->blocks->create(['location' => 'left', 'type' => 'quick-facts', 'settings' => ['facts' => [['text' => 'B', 'bullet' => '']]]]);
        $this->authenticate();

        $body = $this->controller->index($this->get())->body();
        $xpath = $this->xpath($body);

        // A <form> inside a <form> is dropped by the HTML parser, so the arrow
        // payload never reaches the server and the order silently stays put.
        self::assertSame(0, $this->countNodes($xpath, '//form//form'), 'the blocks page must not nest forms');
        self::assertSame(
            0,
            $this->countNodes($xpath, '//form[contains(concat(" ", normalize-space(@class), " "), " block-order ")]'),
            'the sortable list is a container, not a form'
        );

        self::assertGreaterThanOrEqual(
            1,
            $this->countNodes($xpath, '//li[contains(@class, "block-order-item")]//form//input[@name="order"]'),
            'each arrow form posts its own order'
        );
        self::assertSame(1, $this->countNodes($xpath, '//form[contains(@class, "block-order-save")]//input[@name="order"]'), 'one save form carries the dragged order');
        self::assertSame(1, $this->countNodes($xpath, '//form[contains(@class, "block-order-save")]//input[@name="location"]'));
    }

    private function countNodes(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes->length;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
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
