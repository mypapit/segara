<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}> $pages
 * @var string $csrfToken
 * @var string $newPageUrl
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string $editUrlPrefix
 * @var string|null $error
 * @var string|null $notice
 * @var array<string, int> $counts
 * @var string|null $activeState
 * @var string $restoreUrl
 * @var string $pagesUrl
 */
$stateClass = static function (string $state): string {
    return match ($state) {
        'published' => 'chip chip--published',
        'draft' => 'chip chip--draft',
        'trash' => 'chip chip--trash',
        default => 'chip chip--private',
    };
};
$total = array_sum($counts);
$published = $counts['published'] ?? 0;
$drafts = $counts['draft'] ?? 0;
$private = $counts['private'] ?? 0;
$trashed = $counts['trash'] ?? 0;
$filter = static function (?string $state) use ($pagesUrl): string {
    return $state === null ? $pagesUrl : $pagesUrl . '&state=' . $state;
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Pages</title>
</head>
<body class="admin">
<?php $current = 'pages'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Content</span>
            <h1>Pages</h1>
            <p class="lede">Everything the site can publish. Drafts stay private until you publish them.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain"><?= $published ?> published</span>
            <span class="chip chip--plain"><?= $drafts ?> draft<?= $drafts === 1 ? '' : 's' ?></span>
            <a class="button button-primary" href="<?= Html::escape($newPageUrl) ?>">New page</a>
        </div>
    </div>

    <?php if ($notice === 'saved'): ?>
        <p class="notice" role="status">Page saved.</p>
    <?php elseif ($notice === 'restored'): ?>
        <p class="notice" role="status">Page restored as a draft.</p>
    <?php endif; ?>

    <nav class="filter-bar" aria-label="Filter pages by status">
        <a class="chip<?= $activeState === null ? ' chip--active' : ' chip--plain' ?>" href="<?= Html::escape($filter(null)) ?>"<?= $activeState === null ? ' aria-current="page"' : '' ?>>All <span class="num"><?= $total ?></span></a>
        <a class="chip<?= $activeState === 'published' ? ' chip--published' : ' chip--plain' ?>" href="<?= Html::escape($filter('published')) ?>"<?= $activeState === 'published' ? ' aria-current="page"' : '' ?>>Published <span class="num"><?= $published ?></span></a>
        <a class="chip<?= $activeState === 'draft' ? ' chip--draft' : ' chip--plain' ?>" href="<?= Html::escape($filter('draft')) ?>"<?= $activeState === 'draft' ? ' aria-current="page"' : '' ?>>Drafts <span class="num"><?= $drafts ?></span></a>
        <a class="chip<?= $activeState === 'private' ? ' chip--private' : ' chip--plain' ?>" href="<?= Html::escape($filter('private')) ?>"<?= $activeState === 'private' ? ' aria-current="page"' : '' ?>>Private <span class="num"><?= $private ?></span></a>
        <a class="chip<?= $activeState === 'trash' ? ' chip--trash' : ' chip--plain' ?>" href="<?= Html::escape($filter('trash')) ?>"<?= $activeState === 'trash' ? ' aria-current="page"' : '' ?>>Trash <span class="num"><?= $trashed ?></span></a>
    </nav>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <?php if (count($pages) === 0): ?>
        <div class="empty-state">
            <?php if ($activeState !== null): ?>
                <strong>No pages in this view.</strong>
                Choose All to see every page.
            <?php else: ?>
                <strong>Nothing published yet.</strong>
                A page needs only a title to start. Create one and save it as a draft before publishing.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table--stack">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <th scope="col">Address</th>
                        <th scope="col">State</th>
                        <th scope="col">Updated</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $page): ?>
                        <tr>
                            <td data-label="Title"><a class="table-title" href="<?= Html::escape($editUrlPrefix . $page['id']) ?>"><?= Html::escape($page['title']) ?></a></td>
                            <td data-label="Address"><code><?= Html::escape($page['slug']) ?></code></td>
                            <td data-label="State"><span class="<?= $stateClass($page['state']) ?>"><?= Html::escape(ucfirst($page['state'])) ?></span></td>
                            <td data-label="Updated" class="num"><time datetime="<?= Html::escape($page['updated_at']) ?>"><?= Html::escape(substr($page['updated_at'], 0, 10)) ?></time></td>
                            <td data-label="Actions" class="table-actions">
                                <a class="button button--sm" href="<?= Html::escape($editUrlPrefix . $page['id']) ?>">Edit</a>
                                <?php if ($page['state'] === 'trash'): ?>
                                    <form method="post" action="<?= Html::escape($restoreUrl) ?>" class="inline-form">
                                        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                        <input type="hidden" name="page_id" value="<?= Html::escape($page['id']) ?>">
                                        <input type="hidden" name="revision" value="<?= Html::escape((string) $page['revision']) ?>">
                                        <button type="submit" class="button button--sm">Restore</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
