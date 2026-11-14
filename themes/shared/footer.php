<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * The tagline is a footer line above the colophon in every theme. Slate opts out
 * because its band already carries the tagline: it sets `$showFooterTagline =
 * false` before including this file, the same way it opts out of the brand.
 *
 * @var string $footerHtml
 * @var string $siteName
 * @var string $site_tagline
 * @var bool $hasCopyrightBlock
 * @var bool|null $showFooterTagline
 */
$showTagline = ($showFooterTagline ?? true) && trim($site_tagline) !== '';
?>
<footer class="site-footer">
    <?php if (trim($footerHtml) !== ''): ?>
        <div class="site-footer__blocks"><?= $footerHtml ?></div>
    <?php endif; ?>
    <?php if ($showTagline): ?>
        <p class="site-footer__tagline"><?= Html::escape($site_tagline) ?></p>
    <?php endif; ?>
    <?php if (!$hasCopyrightBlock): ?>
        <p class="site-footer__copyright">© <?= gmdate('Y') ?> <?= Html::escape($siteName) ?></p>
    <?php endif; ?>
</footer>
