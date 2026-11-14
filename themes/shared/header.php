<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * The bar is shared by every theme. A theme opts out of the brand by setting
 * `$showBrand = false` before including this file, because Slate puts the site
 * name in its jumbotron instead. Everything else keeps the brand and the menu.
 *
 * @var string $siteName
 * @var string $basePath
 * @var list<array{id: string, parent_id: ?string, label: string, url: string, position: int}> $navigation
 * @var bool|null $showBrand
 */
$hasBrand = $showBrand ?? true;
$hasNav = false;
if ($navigation !== []) {
    $items = $navigation;
    require __DIR__ . '/navigation.php';
    $hasNav = isset($navHtml) && trim((string) $navHtml) !== '';
}
// A bar with neither a brand nor a menu is an empty dark strip with a hairline.
if (!$hasBrand && !$hasNav) {
    return;
}
?>
<header class="site-header<?= $hasBrand ? '' : ' site-header--nav-only' ?>">
    <div class="header-inner">
        <?php if ($hasBrand): ?>
            <a class="site-title" href="<?= Html::escape($basePath === '' ? '/' : $basePath . '/') ?>"><?= Html::escape($siteName) ?></a>
        <?php endif; ?>
        <?php if ($hasNav): ?>
            <nav class="site-nav" aria-label="Primary"><?= (string) $navHtml ?></nav>
        <?php endif; ?>
    </div>
</header>
