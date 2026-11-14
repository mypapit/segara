<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $csrfToken
 * @var string $deleteUrl
 * @var string $pagesUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string $pageId
 * @var string $pageTitle
 * @var int $revision
 * @var string|null $error
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Delete page</title>
</head>
<body class="admin">
<?php $current = 'pages'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main admin-main--narrow" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Permanent removal</span>
            <h1>Delete this page?</h1>
        </div>
        <div class="page-head__actions">
            <a class="button" href="<?= Html::escape($pagesUrl) ?>">Cancel</a>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= Html::escape($deleteUrl) ?>" class="danger-zone">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
        <input type="hidden" name="page_id" value="<?= Html::escape($pageId) ?>">
        <input type="hidden" name="revision" value="<?= Html::escape((string) $revision) ?>">
        <p><strong><?= Html::escape($pageTitle) ?></strong> will be removed for good. Its address stops working and the text cannot be recovered from Trash.</p>
        <div class="field">
            <label for="confirmation">Type the exact page title to confirm</label>
            <input id="confirmation" name="confirmation" type="text" required maxlength="200">
        </div>
        <div class="form-actions">
            <button type="submit" class="button button--danger">Delete permanently</button>
            <a class="button" href="<?= Html::escape($pagesUrl) ?>">Keep the page</a>
        </div>
    </form>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
