<?php

declare(strict_types=1);

namespace Segara\PublicSite;

use Segara\Blocks\PublicBlocks;
use Segara\Config;
use Segara\Content\Markdown;
use Segara\Http\Response;
use Segara\Pages\PageRepository;
use Segara\View\Renderer;

final class HomeController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly Renderer $renderer,
        private readonly ?PublicBlocks $blocks = null,
        /** @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> */
        private readonly array $navigation = [],
    ) {
    }

    public function index(): Response
    {
        $homepageId = $this->pages->homepageId();
        if ($homepageId !== null) {
            $page = $this->pages->getById($homepageId);
            if ($page !== null && $page['state'] === 'published') {
                $html = $this->renderer->render($this->renderer->activeTheme(), 'page', [
                    'title' => $page['title'],
                    'body' => (new Markdown())->render($page['body_markdown']),
                    'csrfToken' => '',
                    'challenge' => false,
                ] + $this->sidebarData() + ['navigation' => $this->navigation]);

                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
            }
        }

        $pages = $this->pages->listHomepage();
        $html = $this->renderer->render($this->renderer->activeTheme(), 'home', [
            'pages' => $pages,
            'basePath' => $this->config->basePath,
        ] + $this->sidebarData() + ['navigation' => $this->navigation]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
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
