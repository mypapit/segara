<?php

declare(strict_types=1);

use Segara\Support\Html;

/**
 * Shared administration shell: skip link and masthead.
 *
 * Primary destinations are fixed by the product requirements: Pages, Media,
 * Settings, Menu, Admin, Log out. Secondary tasks (blocks, transfer, contact
 * messages) live under Settings rather than the primary bar.
 *
 * @var string $basePath
 * @var string $csrfToken
 * @var string $logoutUrl
 * @var string $current  One of pages|media|settings|menu|admin|dashboard
 */
$primary = [
    'pages' => ['Pages', 'pages'],
    'media' => ['Media', 'media'],
    'settings' => ['Settings', 'settings'],
    'menu' => ['Menu', 'menu'],
    'admin' => ['Admin', 'accounts'],
];
?><a class="skip-link" href="#main">Skip to content</a>
<header class="admin-header">
    <nav class="admin-nav" aria-label="Administration">
        <a class="brand" href="<?= Html::escape($basePath . '/admin.php') ?>">
            <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.8 15.2c2.3 0 2.3-1.9 4.6-1.9s2.3 1.9 4.6 1.9 2.3-1.9 4.6-1.9 2.3 1.9 4.6 1.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <path d="M2.8 9.4c2.3 0 2.3-1.9 4.6-1.9s2.3 1.9 4.6 1.9 2.3-1.9 4.6-1.9 2.3 1.9 4.6 1.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" opacity="0.55"/>
            </svg>
            <span>Segara</span>
        </a>
        <ul>
            <?php foreach ($primary as $key => [$label, $action]): ?>
                <li><a href="<?= Html::escape($basePath . '/admin.php?action=' . $action) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= Html::escape($label) ?></a></li>
            <?php endforeach; ?>
            <li>
                <form method="post" action="<?= Html::escape($logoutUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                    <button type="submit" class="link-button">Log out</button>
                </form>
            </li>
        </ul>
    </nav>
</header>
