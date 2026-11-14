<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $csrfToken
 * @var string $deleteUrl
 * @var string $blocksUrl
 * @var string $basePath
 * @var string $blockId
 * @var string $label
 * @var string $location
 * @var string $accountsUrl
 * @var string $logoutUrl
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Delete block</title>
</head>
<body class="admin">
<?php $current = 'settings'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main admin-main--narrow" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Permanent removal</span>
            <h1>Delete this block?</h1>
        </div>
        <div class="page-head__actions">
            <a class="button" href="<?= Html::escape($blocksUrl) ?>">Cancel</a>
        </div>
    </div>

    <form method="post" action="<?= Html::escape($deleteUrl) ?>" class="danger-zone">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
        <input type="hidden" name="confirmation" value="delete">
        <input type="hidden" name="block_id" value="<?= Html::escape($blockId) ?>">
        <p>The <strong><?= Html::escape($label) ?></strong> block is removed from the <?= Html::escape($location) ?>. Other blocks keep their order. This cannot be undone.</p>
        <div class="form-actions">
            <button type="submit" class="button button--danger">Delete block</button>
            <a class="button" href="<?= Html::escape($blocksUrl) ?>">Keep it</a>
        </div>
    </form>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
