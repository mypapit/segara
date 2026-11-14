<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * Renders a nested navigation list from flat, ordered menu items.
 *
 * The parent chain is data, not a guarantee: a stored cycle (import, direct
 * SQL, a future controller bug) would otherwise recurse until the stack
 * overflows. A visited set plus a depth cap keeps the page rendering.
 *
 * @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> $items
 */

$children = [];
$topLevel = [];
foreach ($items as $item) {
    if ($item['parent_id'] === null) {
        $topLevel[] = $item;
    } else {
        $children[$item['parent_id']][] = $item;
    }
}

$visited = [];
$renderLevel = function (array $level, int $depth) use (&$renderLevel, &$visited, $children): string {
    if ($level === [] || $depth > 5) {
        return '';
    }
    $html = '<ul>';
    foreach ($level as $item) {
        if (isset($visited[$item['id']])) {
            continue;
        }
        $visited[$item['id']] = true;
        $html .= '<li><a href="' . Html::escape($item['url']) . '">'
            . Html::escape($item['label']) . '</a>'
            . $renderLevel($children[$item['id']] ?? [], $depth + 1)
            . '</li>';
    }
    $html .= '</ul>';

    return $html === '<ul></ul>' ? '' : $html;
};

$navHtml = $renderLevel($topLevel, 1);
