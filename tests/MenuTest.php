<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Admin\MenuController;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class MenuTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private MenuRepository $menus;
    private PageRepository $pages;
    private AdminRepository $admins;
    private Config $config;
    private Session $session;
    private Csrf $csrf;
    private MenuController $controller;
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
        $this->menus = new MenuRepository($this->pdo);
        $this->pages = new PageRepository($this->pdo);
        $this->admins = new AdminRepository($this->pdo);
        $admin = $this->admins->create('admin', 'Site Administrator', self::PASSWORD);
        $this->adminId = $admin['id'];
        $this->config = Config::fromArray(['base_url' => 'https://example.test', 'environment' => 'test']);
        $this->session = new Session($this->config, $this->storePath . '/sessions');
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->controller = new MenuController(
            $this->config,
            $this->menus,
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

    public function testNestedNavigationRendersResolvedUrlsAndOmitsUnpublishedPages(): void
    {
        $menu = $this->menus->createMenu('Main');
        $page = $this->pages->create(['title' => 'About', 'state' => 'published', 'author_name' => 'Admin']);
        $draft = $this->pages->create(['title' => 'Draft Page', 'state' => 'draft', 'author_name' => 'Admin']);

        $top = $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'About Us', 'target_type' => 'page', 'page_id' => $page['id'], 'position' => 0]);
        $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'Team', 'target_type' => 'custom', 'custom_url' => 'https://example.com/team', 'parent_id' => $top['id'], 'position' => 1]);
        $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'Hidden Draft', 'target_type' => 'page', 'page_id' => $draft['id'], 'position' => 2]);

        $items = $this->menus->navigation($menu['id']);

        self::assertCount(2, $items);
        self::assertSame('About Us', $items[0]['label']);
        self::assertSame('/about', $items[0]['url']);
        self::assertSame('Team', $items[1]['label']);
        self::assertSame($top['id'], $items[1]['parent_id']);

        // navigation.php returns $navHtml; the caller decides where to place it.
        $navHtml = '';
        ob_start();
        require dirname(__DIR__) . '/themes/shared/navigation.php';
        ob_end_clean();
        $html = $navHtml;
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('About Us', $html);
        self::assertStringContainsString('Team', $html);
        self::assertStringNotContainsString('Hidden Draft', $html);
    }

    public function testAddItemEscapesLabelAndRejectsUnsafeTargets(): void
    {
        $menu = $this->menus->createMenu('Main');
        $page = $this->pages->create(['title' => 'About', 'state' => 'published', 'author_name' => 'Admin']);

        $item = $this->menus->addItem(['menu_id' => $menu['id'], 'label' => '<script>x</script>', 'target_type' => 'custom', 'custom_url' => 'https://example.com', 'position' => 0]);
        self::assertSame('<script>x</script>', $item['label']);

        $invalid = [
            ['menu_id' => $menu['id'], 'label' => 'X', 'target_type' => 'custom', 'custom_url' => 'javascript:alert(1)', 'position' => 0],
            ['menu_id' => $menu['id'], 'label' => 'X', 'target_type' => 'page', 'page_id' => 'not-an-id', 'position' => 0],
            ['menu_id' => $menu['id'], 'label' => '', 'target_type' => 'custom', 'custom_url' => '/x', 'position' => 0],
            ['menu_id' => $menu['id'], 'label' => 'X', 'target_type' => 'bogus', 'position' => 0],
        ];
        $rejected = 0;
        foreach ($invalid as $input) {
            try {
                $this->menus->addItem($input);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalid), $rejected);
    }

    public function testAddItemRejectsCycleCrossMenuAndUnknownParent(): void
    {
        $menuA = $this->menus->createMenu('A');
        $menuB = $this->menus->createMenu('B');
        $top = $this->menus->addItem(['menu_id' => $menuA['id'], 'label' => 'Top', 'target_type' => 'custom', 'custom_url' => '/top', 'position' => 0]);

        $crossMenu = ['menu_id' => $menuB['id'], 'label' => 'X', 'target_type' => 'custom', 'custom_url' => '/x', 'parent_id' => $top['id'], 'position' => 0];
        try {
            $this->menus->addItem($crossMenu);
            self::fail('Cross-menu parent was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('same menu', $e->getMessage());
        }

        $unknownParent = ['menu_id' => $menuA['id'], 'label' => 'X', 'target_type' => 'custom', 'custom_url' => '/x', 'parent_id' => str_repeat('f', 32), 'position' => 0];
        try {
            $this->menus->addItem($unknownParent);
            self::fail('Unknown parent was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function testMaximumDepthFiveIsEnforced(): void
    {
        $menu = $this->menus->createMenu('Main');
        $parent = null;
        for ($i = 1; $i <= 5; $i++) {
            $parent = $this->menus->addItem([
                'menu_id' => $menu['id'], 'label' => "Level $i", 'target_type' => 'custom', 'custom_url' => "/l$i",
                'parent_id' => $parent !== null ? $parent['id'] : null, 'position' => 0,
            ]);
        }

        try {
            $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'Too Deep', 'target_type' => 'custom', 'custom_url' => '/deep', 'parent_id' => $parent['id'], 'position' => 0]);
            self::fail('A sixth nesting level was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('five', $e->getMessage());
        }
    }

    public function testMoveItemRejectsCycleUnderDescendant(): void
    {
        $menu = $this->menus->createMenu('Main');
        $a = $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'A', 'target_type' => 'custom', 'custom_url' => '/a', 'position' => 0]);
        $b = $this->menus->addItem(['menu_id' => $menu['id'], 'label' => 'B', 'target_type' => 'custom', 'custom_url' => '/b', 'parent_id' => $a['id'], 'position' => 0]);

        try {
            $this->menus->moveItem($a['id'], $b['id'], 0);
            self::fail('Moving an item under its own descendant was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('descendant', $e->getMessage());
        }
    }

    public function testMenuEndpointsRequireAuthenticationAndCsrf(): void
    {
        $anonymous = $this->controller->index($this->get());
        self::assertSame(302, $anonymous->status());
        self::assertSame('/admin.php?action=login', $anonymous->headers()['Location']);

        $this->authenticate();

        $getCreate = $this->controller->createMenu($this->get());
        self::assertSame(405, $getCreate->status());

        $badCsrf = $this->controller->createMenu($this->post(['name' => 'X', '_csrf' => 'invalid']));
        self::assertSame(400, $badCsrf->status());
        self::assertSame([], $this->menus->listMenus());
    }

    public function testControllerAddsItemAndDeletesItemViaPost(): void
    {
        $menu = $this->menus->createMenu('Main');
        $page = $this->pages->create(['title' => 'About', 'state' => 'published', 'author_name' => 'Admin']);
        $this->authenticate();
        $token = $this->csrf->token();

        $add = $this->controller->addItem($this->post([
            'menu_id' => $menu['id'], 'label' => 'About', 'target_type' => 'page', 'page_id' => $page['id'],
            'position' => '0', '_csrf' => $token,
        ]));
        self::assertSame(303, $add->status());
        $items = $this->menus->listItems($menu['id']);
        self::assertCount(1, $items);

        $refused = $this->controller->deleteItem($this->post(['item_id' => $items[0]['id'], '_csrf' => $token]));
        self::assertSame(422, $refused->status(), 'deletion must be confirmed');
        self::assertCount(1, $this->menus->listItems($menu['id']));

        $delete = $this->controller->deleteItem($this->post([
            'item_id' => $items[0]['id'], 'confirmation' => 'About', '_csrf' => $token,
        ]));
        self::assertSame(303, $delete->status());
        self::assertSame([], $this->menus->listItems($menu['id']));
    }

    public function testControllerEditsAnItemLabel(): void
    {
        $menu = $this->menus->createMenu('Main');
        $item = $this->menus->addItem([
            'menu_id' => $menu['id'], 'label' => 'Old', 'target_type' => 'custom',
            'custom_url' => '/old', 'parent_id' => null, 'position' => 0,
        ]);
        $this->authenticate();

        $response = $this->controller->editItem($this->post([
            'item_id' => $item['id'], 'label' => 'New', 'target_type' => 'custom',
            'custom_url' => '/new', '_csrf' => $this->csrf->token(),
        ]));

        self::assertSame(303, $response->status());
        $items = $this->menus->listItems($menu['id']);
        self::assertSame('New', $items[0]['label']);
        self::assertSame('/new', $items[0]['custom_url']);
    }

    public function testControllerMovesAnItemAmongItsSiblings(): void
    {
        $menu = $this->menus->createMenu('Main');
        foreach (['A', 'B', 'C'] as $index => $label) {
            $this->menus->addItem([
                'menu_id' => $menu['id'], 'label' => $label, 'target_type' => 'custom',
                'custom_url' => '/item-' . strtolower($label), 'parent_id' => null, 'position' => $index,
            ]);
        }
        $items = $this->menus->listItems($menu['id']);
        $this->authenticate();

        $response = $this->controller->moveItem($this->post([
            'item_id' => $items[2]['id'], 'direction' => 'up', '_csrf' => $this->csrf->token(),
        ]));

        self::assertSame(303, $response->status());
        $labels = array_map(static fn(array $row): string => $row['label'], $this->menus->listItems($menu['id']));
        self::assertSame(['A', 'C', 'B'], $labels);
    }

    public function testUpdateItemKeepsAPageTargetReadable(): void
    {
        $menu = $this->menus->createMenu('Main');
        $page = $this->pages->create(['title' => 'About', 'state' => 'published', 'author_name' => 'Admin']);
        $item = $this->menus->addItem([
            'menu_id' => $menu['id'], 'label' => 'About', 'target_type' => 'page',
            'page_id' => $page['id'], 'parent_id' => null, 'position' => 0,
        ]);

        $updated = $this->menus->updateItem($item['id'], [
            'label' => 'About us', 'target_type' => 'page', 'page_id' => $page['id'],
        ]);

        self::assertSame('About us', $updated['label']);
        // The list must still hydrate: a NULL custom_url used to throw here.
        $items = $this->menus->listItems($menu['id']);
        self::assertCount(1, $items);
        self::assertSame('', $items[0]['custom_url']);
    }

    public function testUpdateItemRejectsAPageThatIsNotPublished(): void
    {
        $menu = $this->menus->createMenu('Main');
        $draft = $this->pages->create(['title' => 'Draft', 'state' => 'draft', 'author_name' => 'Admin']);
        $item = $this->menus->addItem([
            'menu_id' => $menu['id'], 'label' => 'X', 'target_type' => 'custom',
            'custom_url' => '/x', 'parent_id' => null, 'position' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->menus->updateItem($item['id'], [
            'label' => 'Draft link', 'target_type' => 'page', 'page_id' => $draft['id'],
        ]);
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
