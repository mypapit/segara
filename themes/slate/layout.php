<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $content
 * @var string $head
 * @var string $theme
 * @var string $themeVersion
 * @var int $sidebars
 * @var string $language
 * @var string $siteName
 * @var string $site_tagline
 * @var string $description
 * @var string $basePath
 * @var bool $isHome
 * @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> $navigation
 * @var string $leftSidebar
 * @var string $rightSidebar
 * @var string $footerHtml
 * @var string|null $announcement
 */
?>
<?php $showBrand = false; // the site name lives in the band, not in the bar ?>
<?php $showFooterTagline = false; // the tagline lives in the band too ?>
<!doctype html>
<html lang="<?= Html::escape($language) ?>">
<head>
<?= $head ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= Html::escape($basePath . '/theme.css.php?theme=' . $theme . '&v=' . $themeVersion) ?>">
</head>
<body data-theme="<?= Html::escape($theme) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<svg class="icon-sprite" aria-hidden="true" focusable="false" width="0" height="0">
    <symbol id="s-arrow" viewBox="0 0 24 24"><path d="M7 17 17 7M9 7h8v8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="s-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 7.5V12l3.1 1.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
    <symbol id="s-book" viewBox="0 0 24 24"><path d="M4 6a2.5 2.5 0 0 1 2.5-2.5H20V19H6.5A2.5 2.5 0 0 0 4 21.5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 19.5V21a1 1 0 0 0 1 1h15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
</svg>
<?php require __DIR__ . '/../shared/header.php'; ?>
<?php require __DIR__ . '/../shared/announcement.php'; ?>
<?php if ($isHome): ?>
<section class="jumbotron">
    <div class="jumbotron__inner">
        <h1 class="jumbotron__title"><?= Html::escape($siteName) ?></h1>
        <?php if ($site_tagline !== ''): ?>
            <p class="jumbotron__tagline"><?= Html::escape($site_tagline) ?></p>
        <?php endif; ?>
        <?php if ($description !== ''): ?>
            <p class="jumbotron__lead"><?= Html::escape($description) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<div class="layout">
    <?php if ($sidebars >= 1 && trim($leftSidebar) !== ''): ?><aside class="sidebar sidebar--left"><?= $leftSidebar ?></aside><?php endif; ?>
    <main id="main" class="main"><?= $content ?></main>
    <?php if ($sidebars >= 2 && trim($rightSidebar) !== ''): ?><aside class="sidebar sidebar--right"><?= $rightSidebar ?></aside><?php endif; ?>
</div>
<?php require __DIR__ . '/../shared/footer.php'; ?>
</body>
</html>
