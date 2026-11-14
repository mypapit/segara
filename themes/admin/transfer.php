<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $csrfToken
 * @var string $exportUrl
 * @var string $importUrl
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 * @var string|null $result
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Transfer</title>
</head>
<body class="admin">
<?php $current = 'settings'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Maintenance</span>
            <h1>Import and export</h1>
            <p class="lede">Move pages between Segara installations as a WXR file. Media and settings travel separately.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain">WXR</span>
            <a class="button" href="<?= Html::escape($basePath . '/admin.php?action=settings') ?>">Back to settings</a>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>
    <?php if ($result !== null): ?>
        <p role="status"><?= Html::escape($result) ?></p>
    <?php endif; ?>

    <div class="split">
        <section class="panel" aria-labelledby="export">
            <div class="panel-head">
                <h2 id="export">Export</h2>
                <span class="muted">All pages, including drafts</span>
            </div>
            <p>The download contains every page in every state. Keep it private: drafts and private pages are included.</p>
            <div class="form-actions">
                <a class="button button-primary" href="<?= Html::escape($exportUrl) ?>">Download WXR export</a>
            </div>
        </section>

        <section class="panel" aria-labelledby="import">
            <div class="panel-head">
                <h2 id="import">Import</h2>
                <span class="muted">Adds pages, never replaces them</span>
            </div>
            <form method="post" action="<?= Html::escape($importUrl) ?>" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <div class="field">
                    <label for="import_file">WXR file</label>
                    <input id="import_file" name="import_file" type="file" accept=".xml,application/xml,text/xml">
                    <p class="hint">Existing pages are skipped rather than overwritten. Media is not included.</p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">Import</button>
                </div>
            </form>
        </section>
    </div>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
