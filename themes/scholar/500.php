<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $errorId
 * @var string $basePath
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Service unavailable</title>
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/theme.css.php?theme=scholar') ?>">
</head>
<body class="fatal-page">
<main class="fatal">
    <h1>Service unavailable</h1>
    <p>Something went wrong while handling your request. If the problem persists, quote the reference <code><?= Html::escape($errorId) ?></code>.</p>
    <p><a href="<?= Html::escape($basePath === '' ? '/' : $basePath . '/') ?>">Back to the home page</a></p>
</main>
</body>
</html>
