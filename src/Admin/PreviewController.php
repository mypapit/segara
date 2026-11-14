<?php

declare(strict_types=1);

namespace Segara\Admin;

use Segara\Auth\AdminRepository;
use Segara\Blocks\PublicBlocks;
use Segara\Auth\Session;
use Segara\Config;
use Segara\Content\Markdown;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Http\SecurityHeaders;
use Segara\Pages\PageRepository;
use Segara\View\Renderer;

/**
 * @phpstan-import-type Admin from AdminRepository
 * @psalm-import-type Admin from AdminRepository
 */
final class PreviewController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly AdminRepository $admins,
        private readonly Session $session,
        private readonly Renderer $renderer,
        private readonly ?PublicBlocks $blocks = null,
    ) {
    }

    public function preview(Request $request): Response
    {
        $current = $this->authenticatedAdmin();
        if ($current === null) {
            return $this->redirect($this->loginUrl());
        }
        if ($request->method() !== 'GET') {
            return $this->methodNotAllowed('GET');
        }

        $id = $request->query('id');
        if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
            return $this->notFound();
        }
        $page = $this->pages->getById($id);
        if ($page === null) {
            return $this->notFound();
        }

        $body = (new Markdown())->render($page['body_markdown']);
        $html = $this->renderer->render($this->renderer->activeTheme(), 'page', [
            'title' => $page['title'],
            'body' => $body,
            'csrfToken' => '',
            'challenge' => false,
        ] + ($this->blocks?->layoutData() ?? []));

        return (new Response(200, SecurityHeaders::forConfig($this->config)
            + ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow'], $html))
            ->asSensitive();
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

    private function methodNotAllowed(string $allow): Response
    {
        return (new Response(
            405,
            SecurityHeaders::forConfig($this->config) + ['Allow' => $allow],
            'Method not allowed.',
        ))->asSensitive();
    }

    private function notFound(): Response
    {
        return (new Response(404, SecurityHeaders::forConfig($this->config), 'Not found.'))->asSensitive();
    }

    private function redirect(string $location): Response
    {
        return (new Response(302, SecurityHeaders::forConfig($this->config) + ['Location' => $location]))->asSensitive();
    }

    private function loginUrl(): string
    {
        return $this->config->basePath . '/admin.php?action=login';
    }
}
