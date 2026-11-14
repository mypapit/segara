<?php

declare(strict_types=1);

namespace Segara\Blocks;

/**
 * Renders administrator-authored blocks for the public templates.
 *
 * One instance per request. The block table is read once and memoised, so a
 * theme with two sidebars and a footer still costs a single query.
 */
final class PublicBlocks
{
    /** @var array{left: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, right: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, footer: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>}|null */
    private ?array $grouped = null;

    public function __construct(
        private readonly BlockRepository $repository,
        private readonly BlockRenderer $renderer,
    ) {
    }

    public function left(): string
    {
        return $this->renderer->renderAll($this->read()['left']);
    }

    public function right(): string
    {
        return $this->renderer->renderAll($this->read()['right']);
    }

    public function footer(): string
    {
        return $this->renderer->renderAll($this->read()['footer']);
    }

    /**
     * True when the administrator supplied their own copyright block, so the
     * generated © line must be suppressed rather than duplicated.
     */
    public function hasFooterCopyright(): bool
    {
        foreach ($this->read()['footer'] as $block) {
            if ($block['type'] === 'copyright') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{left_sidebar: string, right_sidebar: string, footer_html: string, has_copyright_block: bool}
     */
    public function layoutData(): array
    {
        return [
            'left_sidebar' => $this->left(),
            'right_sidebar' => $this->right(),
            'footer_html' => $this->footer(),
            'has_copyright_block' => $this->hasFooterCopyright(),
        ];
    }

    /**
     * @return array{left: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, right: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, footer: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>}
     */
    private function read(): array
    {
        return $this->grouped ??= $this->repository->listGrouped();
    }
}
