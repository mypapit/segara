<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string $basePath */
$homeUrl = $basePath === '' ? '/' : $basePath . '/';
?>
<section class="not-found">
    <p class="not-found__code" aria-hidden="true">404</p>
    <h1>404 — Page not found</h1>
    <p class="not-found__text">The page you are looking for is not available. Check the link you followed, or use the navigation to find what you need.</p>
    <p class="not-found__actions"><a class="button" href="<?= Html::escape($homeUrl) ?>">Back to the homepage <svg class="icon" aria-hidden="true" focusable="false"><use href="#s-go"></use></svg></a></p>
</section>
