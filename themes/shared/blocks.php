<?php
declare(strict_types=1);
use Segara\Blocks\BlockRenderer;

/**
 * Renders a region of blocks (sidebar or footer). Used by themes.
 *
 * @var list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}> $blocks
 * @var BlockRenderer $renderer
 */
echo $renderer->renderAll($blocks);
