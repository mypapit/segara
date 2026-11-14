<?php

declare(strict_types=1);

use Segara\Admin\AccountController;
use Segara\Admin\AuthController;
use Segara\Admin\BlockController;
use Segara\Admin\DashboardController;
use Segara\Admin\MediaController;
use Segara\Admin\MenuController;
use Segara\Admin\MessageController;
use Segara\Admin\PageController;
use Segara\Admin\PreviewController;
use Segara\Admin\SettingsController;
use Segara\Admin\TransferController;
use Segara\Auth\AdminRepository;
use Segara\Auth\CaptchaVerifier;
use Segara\Auth\Csrf;
use Segara\Auth\LoginThrottle;
use Segara\Auth\Session;
use Segara\Blocks\BlockRenderer;
use Segara\Blocks\PublicBlocks;
use Segara\Blocks\BlockRepository;
use Segara\Config;
use Segara\Contact\MessageRepository;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\ErrorHandler;
use Segara\Http\SecurityHeaders;
use Segara\Media\ImageUpload;
use Segara\Media\MediaRepository;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\Storage\Database;
use Segara\Support\Html;
use Segara\Support\Logger;
use Segara\View\Renderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$errorHandler = null;

try {
    $root = getenv('SEGARA_DATA') ?: dirname(__DIR__);
    $config = Config::load($root . '/settings/config.php');
    // The reference matters: a failure anywhere below must be reported through
    // this handler, which writes the log line and mints the reference id. The
    // catch used to build its own response, so a boot failure produced a 500
    // that left no trace in store/logs and gave the operator nothing to quote.
    $errorHandler = new ErrorHandler(new Logger($root . '/store/logs'), $config);
    $errorHandler->register();
    $pdo = Database::connect($root . '/store/segara.sqlite');
    $admins = new AdminRepository($pdo);
    $session = new Session($config, $root . '/store/sessions');
    $controller = new AuthController(
        $config,
        $admins,
        new LoginThrottle($pdo),
        $session,
        new Csrf($session),
        new CaptchaVerifier($config),
    );
    // A query string can arrive as action[]=..., which is a malformed request
    // rather than a server fault. Anything that is not a plain string is
    // treated as an unknown action so it lands on the 404 below instead of
    // raising, being caught, and reported as a 500.
    $requested = $_GET['action'] ?? '';
    $action = is_string($requested) ? $requested : null;
    $accounts = new AccountController($config, $admins, $session, new Csrf($session));
    $pages = new PageController($config, new PageRepository($pdo), $admins, $session, new Csrf($session), new MediaRepository($pdo));
    $preview = new PreviewController($config, new PageRepository($pdo), $admins, $session, new Renderer($config, null, new SettingsRepository($pdo)), new PublicBlocks(new BlockRepository($pdo), new BlockRenderer($config, new MenuRepository($pdo), new MediaRepository($pdo))));
    $media = new MediaController($config, new MediaRepository($pdo), $admins, $session, new Csrf($session), new ImageUpload($root . '/uploads'));
    $menu = new MenuController($config, new MenuRepository($pdo), new PageRepository($pdo), $admins, $session, new Csrf($session), new SettingsRepository($pdo));
    $blocks = new BlockController($config, new BlockRepository($pdo), $admins, $session, new Csrf($session), new BlockRenderer($config, new MenuRepository($pdo), new MediaRepository($pdo)), new MediaRepository($pdo), new MenuRepository($pdo));
    $settings = new SettingsController($config, new SettingsRepository($pdo), new PageRepository($pdo), $admins, $session, new Csrf($session));
    $messages = new MessageController($config, new MessageRepository($pdo), $admins, $session, new Csrf($session));
    $transfer = new TransferController($config, new PageRepository($pdo), $admins, $session, new Csrf($session));
    $dashboard = new DashboardController($config, new PageRepository($pdo), new MediaRepository($pdo), new MessageRepository($pdo), $admins, $session, new Csrf($session), new SettingsRepository($pdo));
    $request = Request::fromGlobals();

    /**
     * Unknown actions used to leave the operator on a three-word plain-text
     * page. They usually arrive from a stale bookmark or a link built by an
     * older panel, so the response names the situation and offers the two
     * routes that do exist. It deliberately depends on nothing but the config:
     * it can be reached before any session or store state is trustworthy, and
     * it must not render the admin template to show a route that failed.
     */
    $notFound = static function (Config $config): Response {
        $basePath = $config->basePath;
        $css = Html::escape($basePath . '/theme.css.php?theme=clarity');
        $admin = Html::escape($basePath . '/admin.php');
        $home = Html::escape($basePath === '' ? '/' : $basePath . '/');
        $body = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Not found — administration</title>
            <link rel="stylesheet" href="{$css}">
            </head>
            <body>
            <main>
            <h1>That link does not open anything</h1>
            <p>The administration action in this address does not exist. It may come from an older version of the panel or from a bookmark that has since moved. Nothing was changed.</p>
            <p><a class="button" href="{$admin}">Go to the administration</a> <a href="{$home}">View the website</a></p>
            </main>
            </body>
            </html>
            HTML;

        return (new Response(
            404,
            SecurityHeaders::forConfig($config) + ['Content-Type' => 'text/html; charset=UTF-8'],
            $body,
        ))->asSensitive();
    };

    $response = match ($action) {
        '' => $dashboard->index(),
        'login' => $controller->login($request),
        'accounts' => $accounts->index($request),
        'account-create' => $accounts->create($request),
        'account-password' => $accounts->changePassword($request),
        'account-delete' => $accounts->delete($request),
        'pages' => $pages->index($request),
        'page-create' => $pages->create($request),
        'page-edit' => $pages->edit($request),
        'page-trash' => $pages->trash($request),
        'page-restore' => $pages->restore($request),
        'page-delete' => $pages->delete($request),
        'page-preview' => $preview->preview($request),
        'media' => $media->index($request),
        'media-upload' => $media->upload($request, is_array($_FILES['image'] ?? null) ? $_FILES['image'] : []),
        'media-delete' => $media->delete($request),
        'menu' => $menu->index($request),
        'menu-create' => $menu->createMenu($request),
        'menu-item-create' => $menu->addItem($request),
        'menu-item-move' => $menu->moveItem($request),
        'menu-item-update' => $menu->editItem($request),
        'menu-primary' => $menu->primaryMenu($request),
        'menu-item-delete' => $menu->deleteItem($request),
        'blocks' => $blocks->index($request),
        'block-create' => $blocks->create($request),
        'block-delete' => $blocks->delete($request),
        'block-delete-confirm' => $blocks->confirmDelete($request),
        'block-edit' => $blocks->edit($request),
        'block-reorder' => $blocks->reorder($request),
        'settings' => $settings->index($request),
        'settings-update' => $settings->update($request),
        'messages' => $messages->index($request),
        'message-read' => $messages->markRead($request),
        'message-delete' => $messages->delete($request),
        'transfer' => $transfer->index($request),
        'transfer-export' => $transfer->export($request),
        'transfer-import' => $transfer->import($request),
        'logout' => $accounts->logout($request),
        default => $notFound($config),
    };
} catch (Throwable $failure) {
    if ($errorHandler instanceof ErrorHandler) {
        // Reported through the handler so the failure is logged with a
        // reference id and rendered as the same 500 page every other route
        // uses.
        $response = $errorHandler->handle($failure);
    } else {
        // Nothing could be constructed, not even the reporter: it needs the
        // config, and loading the config is what failed. A first boot on a
        // host with no settings/config.php would otherwise leave the log
        // silent and the operator nothing to quote, so log best-effort and
        // still mint a reference.
        $dataRoot = getenv('SEGARA_DATA') ?: dirname(__DIR__);
        $reference = bin2hex(random_bytes(8));
        try {
            (new Logger($dataRoot . '/store/logs'))->error($reference, $failure->getMessage(), [
                'class' => $failure::class,
                'line' => $failure->getLine(),
            ]);
        } catch (Throwable) {
            // A logger that cannot write must never replace the 500 it reports.
        }
        $response = (new Response(
            500,
            SecurityHeaders::headers(false) + ['Content-Type' => 'text/plain; charset=UTF-8'],
            'Service unavailable. Reference ' . $reference . '.',
        ))->asSensitive();
    }
}

$response->send();
