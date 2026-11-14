<?php

declare(strict_types=1);

namespace Segara\Admin;

use InvalidArgumentException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Menus\MenuRepository;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class MenuController
{
    public function __construct(
        private readonly Config $config,
        private readonly MenuRepository $menus,
        private readonly PageRepository $pages,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'GET') {
            return $this->methodNotAllowed('GET');
        }

        $notice = $request->query('notice');

        return $this->render(200, $current, null, is_string($notice) ? $notice : null);
    }

    public function createMenu(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $name = $request->post('name');
        try {
            $this->menus->createMenu(is_string($name) ? $name : '');
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->menuUrl(), 303);
    }

    public function addItem(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $parent = $request->post('parent_id');
        $input = [
            'menu_id' => $request->post('menu_id'),
            'label' => $request->post('label'),
            'target_type' => $request->post('target_type'),
            'page_id' => $request->post('page_id'),
            'custom_url' => $request->post('custom_url'),
            'parent_id' => is_string($parent) && $parent !== '' ? $parent : null,
            'position' => $request->post('position'),
        ];
        try {
            $this->menus->addItem($input);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->menuUrl(), 303);
    }

    public function editItem(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $id = $request->post('item_id');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid menu item reference.');
        }

        try {
            $this->menus->updateItem($id, [
                'label' => $request->post('label'),
                'target_type' => $request->post('target_type'),
                'page_id' => $request->post('page_id'),
                'custom_url' => $request->post('custom_url'),
            ]);

            $parent = $request->post('parent_id');
            if (is_string($parent)) {
                $stored = $this->menus->item($id);
                $wanted = $parent !== '' ? $parent : null;
                $sameParent = $stored !== null && $stored['parent_id'] === $wanted;
                if (!$sameParent) {
                    $this->menus->moveItem($id, $wanted, $stored['position'] ?? 0);
                }
            }
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->menuUrl() . '&notice=saved', 303);
    }

    public function primaryMenu(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $menuId = $request->post('menu_id');
        if (!is_string($menuId) || preg_match('/\A[0-9a-f]{32}\z/D', $menuId) !== 1) {
            return $this->render(422, $current, 'Choose a menu for the site header.');
        }

        $exists = false;
        foreach ($this->menus->listMenus() as $menu) {
            if ($menu['id'] === $menuId) {
                $exists = true;
            }
        }
        if (!$exists) {
            return $this->render(422, $current, 'That menu no longer exists.');
        }

        if ($this->settings === null) {
            return $this->render(500, $current, 'Settings are unavailable.');
        }
        $this->settings->setMany(['primary_menu_id' => $menuId]);

        return $this->redirect($this->menuUrl() . '&notice=primary', 303);
    }

    public function moveItem(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $id = $request->post('item_id');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid menu item reference.');
        }

        try {
            $direction = $request->post('direction');
            if ($direction === 'up' || $direction === 'down') {
                $this->menus->moveWithinSiblings($id, $direction === 'up' ? -1 : 1);
            } else {
                $parent = $request->post('parent_id');
                $position = $request->post('position');
                $this->menus->moveItem(
                    $id,
                    is_string($parent) && $parent !== '' ? $parent : null,
                    is_string($position) || is_int($position) ? (int) $position : 0
                );
            }
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->menuUrl() . '&notice=moved', 303);
    }

    public function deleteItem(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'POST') {
            return $this->methodNotAllowed('POST');
        }
        if (!$this->csrf->validate($request)) {
            return $this->render(400, $current, 'Invalid request.');
        }

        $id = $request->post('item_id');
        $confirmation = $request->post('confirmation');
        if (!is_string($id)) {
            return $this->render(422, $current, 'Invalid menu item reference.');
        }
        $item = $this->menus->item($id);
        if ($item === null) {
            return $this->render(422, $current, 'That menu item no longer exists.');
        }
        if (!is_string($confirmation) || !hash_equals($item['label'], trim($confirmation))) {
            return $this->render(422, $current, 'Type the item label exactly to confirm removal.');
        }
        try {
            $this->menus->deleteItem($id);
        } catch (InvalidArgumentException $exception) {
            return $this->render(422, $current, $exception->getMessage());
        }

        return $this->redirect($this->menuUrl(), 303);
    }

    /** @return Admin|null */
    private function authenticatedAdmin(): ?array
    {
        $adminId = $this->session->adminId();
        if ($adminId === null) {
            return null;
        }
        $admin = $this->admins->findById($adminId);
        if ($admin === null) {
            $this->session->logout();
            return null;
        }
        if (!$this->session->hasValidAuthentication($admin['auth_epoch'])) {
            return null;
        }

        return $admin;
    }

    /** @param Admin $current */
    private function render(int $status, array $current, ?string $error = null, ?string $notice = null): Response
    {
        $menus = $this->menus->listMenus();
        $itemsByMenu = [];
        foreach ($menus as $menu) {
            $itemsByMenu[$menu['id']] = $this->menus->listItems($menu['id']);
        }
        $pages = $this->pages->listAdmin();
        $csrfToken = $this->csrf->token();
        $menuUrl = $this->menuUrl();
        $createMenuUrl = $this->url('menu-create');
        $addItemUrl = $this->url('menu-item-create');
        $moveItemUrl = $this->url('menu-item-move');
        $deleteItemUrl = $this->url('menu-item-delete');
        $editItemUrl = $this->url('menu-item-update');
        $primaryUrl = $this->url('menu-primary');
        $primaryMenuId = $this->settings?->get('primary_menu_id');
        $accountsUrl = $this->url('accounts');
        $logoutUrl = $this->url('logout');
        $basePath = $this->config->basePath;
        $template = dirname(__DIR__, 2) . '/themes/admin/menu.php';
        $render = static function () use (
            $template, $menus, $itemsByMenu, $pages, $csrfToken, $menuUrl, $createMenuUrl, $addItemUrl,
            $moveItemUrl, $deleteItemUrl, $editItemUrl, $primaryUrl, $primaryMenuId,
            $accountsUrl, $logoutUrl, $basePath, $error, $notice
        ): string {
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new \RuntimeException('Unable to render menu administration.');
                }
                return $body;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        };

        return (new Response(
            $status,
            SecurityHeaders::forConfig($this->config)
                + ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow'],
            $render(),
        ))->asSensitive();
    }

    private function methodNotAllowed(string $allow): Response
    {
        return (new Response(
            405,
            SecurityHeaders::forConfig($this->config) + ['Allow' => $allow],
            'Method not allowed.',
        ))->asSensitive();
    }

    private function redirect(string $location, int $status = 302): Response
    {
        return (new Response($status, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function menuUrl(): string
    {
        return $this->url('menu');
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }
}
