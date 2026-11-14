<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, name: string, created_at: string, updated_at: string}> $menus
 * @var array<string, list<array{id: string, menu_id: string, parent_id: ?string, target_type: string, page_id: ?string, custom_url: string, label: string, position: int}>> $itemsByMenu
 * @var list<array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}> $pages
 * @var string $csrfToken
 * @var string $menuUrl
 * @var string $createMenuUrl
 * @var string $addItemUrl
 * @var string $moveItemUrl
 * @var string $deleteItemUrl
 * @var string $editItemUrl
 * @var string $primaryUrl
 * @var string|null $primaryMenuId
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 */
$notice = $notice ?? null;

/** Flatten a menu into depth-tagged rows so nesting is visible in the list. */
$flatten = static function (array $items): array {
    $byParent = [];
    foreach ($items as $item) {
        $byParent[$item['parent_id'] ?? ''][] = $item;
    }
    $rows = [];
    $walk = static function (string $parent, int $depth) use (&$walk, $byParent, &$rows): void {
        if ($depth > 5) {
            return;
        }
        foreach ($byParent[$parent] ?? [] as $item) {
            $rows[] = [$item, $depth];
            $walk($item['id'], $depth + 1);
        }
    };
    $walk('', 0);

    return $rows;
};
$itemCount = 0;
foreach ($menus as $menu) {
    $itemCount += count($itemsByMenu[$menu['id']] ?? []);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Navigation</title>
</head>
<body class="admin">
<?php $current = 'menu'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Structure</span>
            <h1>Navigation</h1>
            <p class="lede">Menus are ordered lists of links. One menu appears in the site header; the rest can be placed with a Menu block.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain"><?= count($menus) ?> menu<?= count($menus) === 1 ? '' : 's' ?></span>
            <span class="chip chip--plain"><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>
    <?php if ($notice === 'moved' || $notice === 'saved'): ?>
        <p role="status" class="notice">Menu updated.</p>
    <?php elseif ($notice === 'primary'): ?>
        <p role="status" class="notice">Header menu changed.</p>
    <?php endif; ?>

    <div class="split">
        <section class="panel" aria-labelledby="primary-menu">
            <div class="panel-head">
                <h2 id="primary-menu">Header menu</h2>
                <span class="muted">Shown on every public page.</span>
            </div>
            <form method="post" action="<?= Html::escape($primaryUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <div class="field">
                    <label for="primary-menu-id">Menu</label>
                    <select id="primary-menu-id" name="menu_id">
                        <?php foreach ($menus as $menu): ?>
                            <option value="<?= Html::escape($menu['id']) ?>"<?= $primaryMenuId === $menu['id'] ? ' selected' : '' ?>><?= Html::escape($menu['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">Use this menu</button>
                </div>
                <p class="panel-foot">Other menus stay available to a Menu block in a sidebar or footer.</p>
            </form>
        </section>

        <section class="panel" aria-labelledby="new-menu">
            <div class="panel-head">
                <h2 id="new-menu">New menu</h2>
                <span class="muted">A container for items. Nothing is public until selected above.</span>
            </div>
            <form method="post" action="<?= Html::escape($createMenuUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <div class="field">
                    <label for="menu-name">Name</label>
                    <input id="menu-name" name="name" type="text" required maxlength="200">
                    <p class="hint">For your own reference, e.g. “Footer links”.</p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">Create menu</button>
                </div>
            </form>
        </section>
    </div>

    <?php foreach ($menus as $menu): ?>
        <?php $items = $itemsByMenu[$menu['id']] ?? []; $rows = $flatten($items); ?>
        <section class="panel" aria-labelledby="menu-<?= Html::escape($menu['id']) ?>">
            <div class="panel-head">
                <h2 id="menu-<?= Html::escape($menu['id']) ?>"><?= Html::escape($menu['name']) ?></h2>
                <span class="chip chip--plain"><?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($rows === []): ?>
                <div class="empty-state">
                    <strong>No items yet.</strong>
                    Add the first one below. Items can point at a page or an outside address, and can sit under another item.
                </div>
            <?php else: ?>
                <ol class="menu-tree">
                    <?php foreach ($rows as [$item, $depth]): ?>
                        <li class="menu-node menu-node--depth-<?= (int) $depth ?>">
                            <span class="menu-label"><?= Html::escape($item['label']) ?></span>
                            <span class="chip chip--plain"><?= Html::escape($item['target_type'] === 'page' ? 'Page' : 'Custom link') ?></span>
                            <?php if ($depth > 0): ?>
                                <span class="menu-meta">level <?= (int) $depth + 1 ?></span>
                            <?php endif; ?>
                            <span class="menu-controls">
                                <form method="post" action="<?= Html::escape($moveItemUrl) ?>" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                    <input type="hidden" name="item_id" value="<?= Html::escape($item['id']) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" aria-label="Move <?= Html::escape($item['label']) ?> up">&uarr;</button>
                                </form>
                                <form method="post" action="<?= Html::escape($moveItemUrl) ?>" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                    <input type="hidden" name="item_id" value="<?= Html::escape($item['id']) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" aria-label="Move <?= Html::escape($item['label']) ?> down">&darr;</button>
                                </form>
                            </span>
                            <details class="menu-details">
                                <summary>Edit</summary>
                                <form method="post" action="<?= Html::escape($editItemUrl) ?>">
                                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                    <input type="hidden" name="item_id" value="<?= Html::escape($item['id']) ?>">
                                    <div class="grid-2">
                                        <div class="field">
                                            <label for="label-<?= Html::escape($item['id']) ?>">Label</label>
                                            <input id="label-<?= Html::escape($item['id']) ?>" name="label" type="text" required maxlength="200" value="<?= Html::escape($item['label']) ?>">
                                        </div>
                                        <div class="field">
                                            <label for="type-<?= Html::escape($item['id']) ?>">Points to</label>
                                            <select id="type-<?= Html::escape($item['id']) ?>" name="target_type">
                                                <option value="page"<?= $item['target_type'] === 'page' ? ' selected' : '' ?>>A page</option>
                                                <option value="custom"<?= $item['target_type'] === 'custom' ? ' selected' : '' ?>>A custom URL</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="page-<?= Html::escape($item['id']) ?>">Page</label>
                                            <select id="page-<?= Html::escape($item['id']) ?>" name="page_id">
                                                <option value="">— none —</option>
                                                <?php foreach ($pages as $page): ?>
                                                    <option value="<?= Html::escape($page['id']) ?>"<?= $item['page_id'] === $page['id'] ? ' selected' : '' ?>><?= Html::escape($page['title']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="url-<?= Html::escape($item['id']) ?>">Custom URL</label>
                                            <input id="url-<?= Html::escape($item['id']) ?>" name="custom_url" type="text" maxlength="2000" value="<?= Html::escape($item['custom_url']) ?>">
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <p class="field">
                                            <label for="parent-<?= Html::escape($item['id']) ?>">Under</label>
                                            <select id="parent-<?= Html::escape($item['id']) ?>" name="parent_id">
                                                <option value="">— top level —</option>
                                                <?php foreach ($rows as [$parentItem, $parentDepth]): ?>
                                                    <?php if ((int) $parentDepth > 1 || $parentItem['id'] === $item['id']) { continue; } ?>
                                                    <option value="<?= Html::escape($parentItem['id']) ?>"<?= $item['parent_id'] === $parentItem['id'] ? ' selected' : '' ?>><?= str_repeat('— ', (int) $parentDepth) . Html::escape($parentItem['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="hint">Changing this moves the item, with its children.</span>
                                        </p>
                                        <button type="submit" class="button button-primary">Save item</button>
                                    </div>
                                </form>
                            </details>
                            <details class="menu-details menu-details--danger">
                                <summary>Remove</summary>
                                <form method="post" action="<?= Html::escape($deleteItemUrl) ?>">
                                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                    <input type="hidden" name="item_id" value="<?= Html::escape($item['id']) ?>">
                                    <div class="field">
                                        <label for="confirm-<?= Html::escape($item['id']) ?>">Type “<?= Html::escape($item['label']) ?>” to confirm</label>
                                        <input id="confirm-<?= Html::escape($item['id']) ?>" name="confirmation" type="text" required>
                                        <p class="hint">Child items are removed with it.</p>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="button button--danger">Remove item</button>
                                    </div>
                                </form>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <h3>Add an item to <?= Html::escape($menu['name']) ?></h3>
            <form method="post" action="<?= Html::escape($addItemUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <input type="hidden" name="menu_id" value="<?= Html::escape($menu['id']) ?>">
                <div class="grid-2">
                    <div class="field">
                        <label for="label-<?= Html::escape($menu['id']) ?>">Label</label>
                        <input id="label-<?= Html::escape($menu['id']) ?>" name="label" type="text" required maxlength="200">
                    </div>
                    <div class="field">
                        <label for="type-<?= Html::escape($menu['id']) ?>">Points to</label>
                        <select id="type-<?= Html::escape($menu['id']) ?>" name="target_type">
                            <option value="page">A page</option>
                            <option value="custom">A custom URL</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="page-<?= Html::escape($menu['id']) ?>">Page</label>
                        <select id="page-<?= Html::escape($menu['id']) ?>" name="page_id">
                            <option value="">— none —</option>
                            <?php foreach ($pages as $page): ?>
                                <option value="<?= Html::escape($page['id']) ?>"><?= Html::escape($page['title']) ?> (<?= Html::escape($page['slug']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="url-<?= Html::escape($menu['id']) ?>">Custom URL</label>
                        <input id="url-<?= Html::escape($menu['id']) ?>" name="custom_url" type="text" maxlength="2000">
                    </div>
                    <div class="field">
                        <label for="parent-<?= Html::escape($menu['id']) ?>">Under</label>
                        <select id="parent-<?= Html::escape($menu['id']) ?>" name="parent_id">
                            <option value="">— top level —</option>
                            <?php foreach ($rows as [$parentItem, $parentDepth]): ?>
                                <?php if ((int) $parentDepth > 1) { continue; } ?>
                                <option value="<?= Html::escape($parentItem['id']) ?>"><?= str_repeat('— ', (int) $parentDepth) . Html::escape($parentItem['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="position" value="<?= count($items) ?>">
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Add item</button>
                </div>
            </form>
        </section>
    <?php endforeach; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
