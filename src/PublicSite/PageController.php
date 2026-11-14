<?php

declare(strict_types=1);

namespace Segara\PublicSite;

use Segara\Auth\Csrf;
use Segara\Auth\PageAccess;
use Segara\Blocks\PublicBlocks;
use Segara\Contact\ContactController;
use Segara\Content\Markdown;
use Segara\Http\Request;
use Segara\Http\Response;
use Segara\Pages\PageRepository;
use Segara\View\Renderer;

final class PageController
{
    /**
     * Shown instead of the page title while a private page is still locked.
     * The title is part of the protected content: a visitor who has not
     * entered the password must not learn what the page is called.
     */
    private const CHALLENGE_TITLE = 'Protected page';

    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageAccess $access,
        private readonly Renderer $renderer,
        private readonly Csrf $csrf,
        private readonly ?ContactController $contact = null,
        private readonly ?PublicBlocks $blocks = null,
        /** @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> */
        private readonly array $navigation = [],
    ) {
    }

    public function show(string $slug, Request $request): Response
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return $this->notFound();
        }

        $page = $this->pages->getBySlug($slug);
        if ($page === null || !in_array($page['state'], ['published', 'private'], true)) {
            return $this->notFound();
        }

        $private = $page['state'] === 'private';

        if ($private && !$this->access->unlocked($page['id'], (string) $page['password_hash'])) {
            $attempted = $request->method() === 'POST';
            $unlocked = $attempted
                && $this->access->challenge($page['id'], (string) $page['password_hash'], $request);

            if (!$unlocked) {
                $challenge = $this->renderer->render($this->renderer->activeTheme(), 'page', [
                    // Never the real title: the unlocked page announces it.
                    'title' => self::CHALLENGE_TITLE,
                    'body' => '',
                    'csrfToken' => $this->csrf->token(),
                    'challenge' => true,
                    'challengeError' => $attempted ? 'That password is not correct. Try again.' : null,
                ] + $this->sidebarData() + ['navigation' => $this->navigation]);

                return (new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $challenge))->asSensitive();
            }
        }

        $body = (new Markdown())->render($page['body_markdown']);
        if ($page['show_contact_form'] === 1 && $this->contact !== null) {
            $body .= $this->contact->form($request);
        }

        $html = $this->renderer->render($this->renderer->activeTheme(), 'page', [
            'title' => $page['title'],
            'body' => $body,
            'csrfToken' => '',
            'challenge' => false,
            'challengeError' => null,
            // The instant the article dates itself from. A page published before
            // the column existed still has created_at, so it is never blank.
            'page_published_at' => $page['published_at'] ?? $page['created_at'],
        ] + $this->sidebarData() + ['navigation' => $this->navigation]);

        $response = new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);

        return $private ? $response->asSensitive() : $response;
    }

    public function notFound(): Response
    {
        $body = $this->renderer->render($this->renderer->activeTheme(), 'error', $this->sidebarData() + ['navigation' => $this->navigation]);

        return (new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], $body))->asSensitive();
    }

    /**
     * @return array{left_sidebar: string, right_sidebar: string, footer_html: string, has_copyright_block: bool}
     */
    private function sidebarData(): array
    {
        if ($this->blocks === null) {
            return ['left_sidebar' => '', 'right_sidebar' => '', 'footer_html' => '', 'has_copyright_block' => false];
        }

        return $this->blocks->layoutData();
    }
}
