<?php

declare(strict_types=1);

use Segara\Support\Html;

/**
 * @var array{id: string, username: string, display_name: string, password_hash: string, auth_epoch: int, created_at: string, updated_at: string} $admin
 * @var array{published: int, draft: int, trash: int, media: int, unread: int} $stats
 * @var list<array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}> $recent
 * @var string $csrfToken
 * @var string $basePath
 * @var string $logoutUrl
 * @var string $pagesUrl
 * @var string $newPageUrl
 * @var string $mediaUrl
 * @var string $menuUrl
 * @var string $blocksUrl
 * @var string $settingsUrl
 * @var string $messagesUrl
 * @var string $transferUrl
 * @var string $accountsUrl
 * @var string $homeUrl
 * @var string $editUrlPrefix
 * @var string $themeName
 * @var int $themeSidebars
 */
$sidebarLabel = $themeSidebars === 0 ? 'No sidebars' : ($themeSidebars === 1 ? 'One sidebar' : 'Two sidebars');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Administration</title>
</head>
<body class="admin">
<?php $current = 'dashboard'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Overview</span>
            <h1>Good to see you, <?= Html::escape($admin['display_name']) ?></h1>
            <p class="lede">Signed in as <strong><?= Html::escape($admin['username']) ?></strong>. Everything below changes the live site.</p>
        </div>
        <div class="page-head__actions">
            <a class="button" href="<?= Html::escape($homeUrl) ?>">View site</a>
            <a class="button button-primary" href="<?= Html::escape($newPageUrl) ?>">New page</a>
        </div>
    </div>

    <section class="stat-grid" aria-label="At a glance">
        <a class="stat" href="<?= Html::escape($pagesUrl) ?>"><span class="stat-number"><?= $stats['published'] ?></span><span class="stat-label">Published</span></a>
        <a class="stat" href="<?= Html::escape($pagesUrl) ?>"><span class="stat-number"><?= $stats['draft'] ?></span><span class="stat-label">Drafts</span></a>
        <a class="stat" href="<?= Html::escape($pagesUrl) ?>"><span class="stat-number"><?= $stats['trash'] ?></span><span class="stat-label">Trash</span></a>
        <a class="stat" href="<?= Html::escape($mediaUrl) ?>"><span class="stat-number"><?= $stats['media'] ?></span><span class="stat-label">Media</span></a>
        <a class="stat" href="<?= Html::escape($messagesUrl) ?>"><span class="stat-number"><?= $stats['unread'] ?></span><span class="stat-label">Unread</span></a>
    </section>

    <div class="split--wide split">
        <section class="panel panel--flush" aria-labelledby="recent-heading">
            <div class="panel-head">
                <h2 id="recent-heading">Recently updated pages</h2>
                <a class="button button--sm button--ghost" href="<?= Html::escape($pagesUrl) ?>">All pages</a>
            </div>
            <?php if ($recent === []): ?>
                <div class="panel-foot">
                    No pages yet. <a href="<?= Html::escape($newPageUrl) ?>">Create the first one</a> — a title is enough to start.
                </div>
            <?php else: ?>
                <table class="table--stack">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">State</th>
                            <th scope="col">Updated</th>
                            <th scope="col"><span class="hint">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $page): ?>
                            <tr>
                                <td data-label="Title"><a class="table-title" href="<?= Html::escape($editUrlPrefix . $page['id']) ?>"><?= Html::escape($page['title']) ?></a></td>
                                <td data-label="State"><span class="chip chip--<?= Html::escape($page['state'] === 'published' ? 'published' : ($page['state'] === 'draft' ? 'draft' : ($page['state'] === 'trash' ? 'trash' : 'private'))) ?>"><?= Html::escape(ucfirst($page['state'])) ?></span></td>
                                <td data-label="Updated" class="num"><?= Html::escape(substr($page['updated_at'], 0, 10)) ?></td>
                                <td data-label="Open" class="table-actions"><a class="button button--sm" href="<?= Html::escape($editUrlPrefix . $page['id']) ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <aside>
            <section class="panel" aria-labelledby="appearance-heading">
                <div class="panel-head">
                    <h2 id="appearance-heading">Appearance</h2>
                </div>
                <p class="panel-note"><strong><?= Html::escape($themeName) ?></strong> · <?= Html::escape($sidebarLabel) ?></p>
                <div class="form-actions">
                    <a class="button button--sm" href="<?= Html::escape($settingsUrl) ?>">Change theme</a>
                    <a class="button button--sm" href="<?= Html::escape($homeUrl) ?>">Preview</a>
                </div>
                <p class="panel-foot">Blocks and menus stay in place when you switch themes.</p>
            </section>

            <section class="panel" aria-labelledby="tasks-heading">
                <div class="panel-head">
                    <h2 id="tasks-heading">Go to</h2>
                </div>
                <ul class="link-list">
                    <li><a href="<?= Html::escape($mediaUrl) ?>">Media library</a></li>
                    <li><a href="<?= Html::escape($menuUrl) ?>">Navigation</a></li>
                    <li><a href="<?= Html::escape($blocksUrl) ?>">Sidebar and footer blocks</a></li>
                    <li><a href="<?= Html::escape($messagesUrl) ?>">Contact messages</a></li>
                    <li><a href="<?= Html::escape($transferUrl) ?>">Import and export</a></li>
                    <li><a href="<?= Html::escape($accountsUrl) ?>">Administrators</a></li>
                </ul>
            </section>
        </aside>
    </div>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
