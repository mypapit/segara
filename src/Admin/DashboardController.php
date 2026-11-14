<?php

declare(strict_types=1);

namespace Segara\Admin;

use RuntimeException;
use Segara\Auth\AdminRepository;
use Segara\Auth\Csrf;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Contact\MessageRepository;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Media\MediaRepository;
use Segara\Pages\PageRepository;
use Segara\Settings\SettingsRepository;
use Segara\View\Themes;
use Throwable;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class DashboardController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly MediaRepository $media,
        private readonly MessageRepository $messages,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }

        return $this->renderDashboard($current);
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

    /** @param Admin $admin */
    private function renderDashboard(array $admin): Response
    {
        $pages = $this->pages->listAdmin();
        $published = 0;
        $draft = 0;
        $trash = 0;
        foreach ($pages as $page) {
            if ($page['state'] === 'published') {
                ++$published;
            } elseif ($page['state'] === 'draft') {
                ++$draft;
            } elseif ($page['state'] === 'trash') {
                ++$trash;
            }
        }

        $mediaCount = count($this->media->list());
        $unread = 0;
        foreach ($this->messages->list() as $message) {
            if ($message['is_read'] === 0) {
                ++$unread;
            }
        }

        $themeId = $this->settings->all()['theme'] ?? 'clarity';
        $themeId = in_array($themeId, Themes::ALL, true) ? $themeId : 'clarity';
        $themeInfo = (new Themes(dirname(__DIR__, 2) . '/themes'))->info($themeId);

        $data = [
            'admin' => $admin,
            'stats' => [
                'published' => $published,
                'draft' => $draft,
                'trash' => $trash,
                'media' => $mediaCount,
                'unread' => $unread,
            ],
            'recent' => array_slice($pages, 0, 5),
            'themeName' => $themeInfo['name'],
            'themeSidebars' => $themeInfo['sidebars'],
            'csrfToken' => $this->csrf->token(),
            'basePath' => $this->config->basePath,
            'logoutUrl' => $this->url('logout'),
            'pagesUrl' => $this->url('pages'),
            'newPageUrl' => $this->url('page-create'),
            'mediaUrl' => $this->url('media'),
            'menuUrl' => $this->url('menu'),
            'blocksUrl' => $this->url('blocks'),
            'settingsUrl' => $this->url('settings'),
            'messagesUrl' => $this->url('messages'),
            'transferUrl' => $this->url('transfer'),
            'accountsUrl' => $this->url('accounts'),
            'homeUrl' => $this->config->basePath . '/',
            'editUrlPrefix' => $this->url('page-edit') . '&id=',
        ];

        $template = dirname(__DIR__, 2) . '/themes/admin/dashboard.php';
        $body = $this->render($template, $data);

        return (new Response(
            200,
            SecurityHeaders::forConfig($this->config)
                + ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow'],
            $body,
        ))->asSensitive();
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): string
    {
        $render = static function () use ($template, $data): string {
            extract($data, EXTR_SKIP);
            $level = ob_get_level();
            ob_start();
            try {
                require $template;
                $body = ob_get_clean();
                if (!is_string($body)) {
                    throw new RuntimeException('Unable to render the administration dashboard.');
                }

                return $body;
            } catch (Throwable $exception) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                throw $exception;
            }
        };

        return $render();
    }

    private function url(string $action): string
    {
        return $this->config->basePath . '/admin.php?action=' . $action;
    }

    private function loginUrl(): string
    {
        return $this->url('login');
    }

    private function redirect(string $location): Response
    {
        return (new Response(302, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }
}
