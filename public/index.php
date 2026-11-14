<?php

declare(strict_types=1);

use Segara\Config;
use Segara\Contact\{ContactController, MessageRepository};
use Segara\Feeds\RssController;
use Segara\Blocks\{BlockRenderer, BlockRepository, PublicBlocks};
use Segara\Menus\MenuRepository;
use Segara\Media\MediaRepository;
use Segara\Storage\Database;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Auth\{Session,Csrf,PageAccess};
use Segara\View\Renderer;
use Segara\Http\{Request,Response};
use Segara\Http\ErrorHandler;
use Segara\Support\Logger;
use Segara\PublicSite\{PageController,HomeController};

require dirname(__DIR__) . '/vendor/autoload.php';
$data = getenv('SEGARA_DATA') ?: dirname(__DIR__);
$config = Config::load($data . '/settings/config.php');
(new ErrorHandler(new Logger($data . '/store/logs'), $config))->register();
$pdo = Database::connect($data . '/store/segara.sqlite');
$session = new Session($config, $data . '/store/sessions');
$csrf = new Csrf($session);
$pages = new PageRepository($pdo);
$renderer = new Renderer($config, null, new SettingsRepository($pdo));
$blocks = new PublicBlocks(new BlockRepository($pdo), new BlockRenderer($config, new MenuRepository($pdo), new MediaRepository($pdo)));
$menus = new MenuRepository($pdo);
$settingsRepository = new SettingsRepository($pdo);
$primaryMenuId = $settingsRepository->get('primary_menu_id');
if ($primaryMenuId === null) {
    $menuList = $menus->listMenus();
    $primaryMenuId = $menuList[0]['id'] ?? null;
}
$navigation = is_string($primaryMenuId) ? $menus->navigation($primaryMenuId) : [];
if ($navigation === []) {
    // The stored menu may have been deleted; fall back to the oldest one.
    $menuList = $menus->listMenus();
    $navigation = $menuList === [] ? [] : $menus->navigation($menuList[0]['id']);
}
$contact = new ContactController(new MessageRepository($pdo), $session, $csrf, $pdo);
$controller = new PageController($pages, new PageAccess($pdo,$session,$csrf), $renderer, $csrf, $contact, $blocks, $navigation);
$home = new HomeController($config, $pages, $renderer, $blocks, $navigation);
$rss = new RssController($config, $pages, new MediaRepository($pdo));
$request = Request::fromGlobals();
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$prefix = $config->basePath;
if (!is_string($path) || ($prefix !== '' && !str_starts_with($path, $prefix))) { $response=$controller->notFound(); }
else {
    $route = substr($path, strlen($prefix));
    if (isset($_GET['page']) && is_string($_GET['page']) && count($_GET) === 1 && ($route === '' || $route === '/')) $response=$controller->show($_GET['page'],$request);
    elseif ($route === '/rss.xml') $response=$rss->feed();
    elseif (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#', $route, $m) === 1) $response=$controller->show($m[1],$request);
    elseif ($route === '' || $route === '/') $response=$home->index();
    else $response=$controller->notFound();
}
(new Response($response->status(), $response->headers(), $request->method()==='HEAD' ? '' : $response->body()))->send();
