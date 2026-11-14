<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string $basePath */
?>
<section class="notice">
    <p class="notice__code" aria-hidden="true">404</p>
    <h1>Page not found</h1>
    <p>The page you are looking for is not available. Check the link you followed, or use the navigation to find what you need.</p>
    <a class="notice__link" href="<?= Html::escape($basePath === '' ? '/' : $basePath . '/') ?>">Back to the home page <svg class="icon" aria-hidden="true" focusable="false"><use href="#s-go"></use></svg></a>
</section>
