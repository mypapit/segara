<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $content
 * @var string $head
 * @var string $theme
 * @var int $sidebars
 * @var string $language
 * @var string $siteName
 * @var string $basePath
 * @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> $navigation
 * @var string $leftSidebar
 * @var string $rightSidebar
 * @var string $footerHtml
 * @var string|null $announcement
 */
?><!doctype html>
<html lang="<?= Html::escape($language) ?>">
<head>
<?= $head ?>
<link rel="stylesheet" href="<?= Html::escape($basePath . '/theme.css.php?theme=' . $theme . '&v=' . $themeVersion) ?>">
</head>
<body data-theme="<?= Html::escape($theme) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php require __DIR__ . '/../shared/header.php'; ?>
<?php require __DIR__ . '/../shared/announcement.php'; ?>
<div class="layout">
    <?php /* One rail, placed on the right: the article comes first in the DOM so
             screen readers and stacked layouts both reach it before the rail. */ ?>
    <main id="main" class="main"><?= $content ?></main>
    <?php if ($sidebars >= 1 && trim($leftSidebar) !== ''): ?><aside class="sidebar sidebar--rail"><?= $leftSidebar ?></aside><?php endif; ?>
</div>
<?php require __DIR__ . '/../shared/footer.php'; ?>
</body>
</html>
