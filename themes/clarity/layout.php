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

<?php /* Three glyphs, inline so the CSP never sees a second origin. No xmlns
         attribute: HTML puts inline <svg> in the SVG namespace, and the theme
         tests reject a colon-slash-slash in a template. */ ?>
<svg class="icon-sprite" aria-hidden="true" focusable="false" width="0" height="0">
    <symbol id="s-arrow" viewBox="0 0 24 24"><path d="M7 17 17 7M9 7h8v8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="s-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 7.5V12l3.1 1.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
    <symbol id="s-book" viewBox="0 0 24 24"><path d="M4 6a2.5 2.5 0 0 1 2.5-2.5H20V19H6.5A2.5 2.5 0 0 0 4 21.5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 19.5V21a1 1 0 0 0 1 1h15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
    <symbol id="s-register" viewBox="0 0 24 24"><path d="M2 22V4a2 2 0 0 1 2-2h18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="square"/><rect x="0.5" y="20.5" width="3" height="3" fill="currentColor"/></symbol>
</svg>

<?php require __DIR__ . '/../shared/header.php'; ?>
<?php require __DIR__ . '/../shared/announcement.php'; ?>
<div class="layout">
    <?php if ($sidebars >= 1 && trim($leftSidebar) !== ''): ?><aside class="sidebar sidebar--left"><?= $leftSidebar ?></aside><?php endif; ?>
    <main id="main" class="main"><?= $content ?></main>
    <?php if ($sidebars >= 2 && trim($rightSidebar) !== ''): ?><aside class="sidebar sidebar--right"><?= $rightSidebar ?></aside><?php endif; ?>
</div>
<?php require __DIR__ . '/../shared/footer.php'; ?>
</body>
</html>
