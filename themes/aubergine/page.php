<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $title
 * @var string $body
 * @var bool $challenge
 * @var string $csrfToken
 * @var string|null $challengeError
 */
if ($challenge): ?>
    <form method="post" class="challenge" action="">
        <svg class="challenge__lock" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="4.5" y="10" width="15" height="10.5" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
            <path d="M8.2 10V7.6a3.8 3.8 0 0 1 7.6 0V10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <h1><?= Html::escape($title) ?></h1>
        <?php if (($challengeError ?? null) !== null): ?>
            <p role="alert" class="challenge__error"><?= Html::escape((string) $challengeError) ?></p>
        <?php endif; ?>
        <p>
            <label for="page-password">Password</label>
            <input id="page-password" type="password" name="password" required maxlength="1024">
        </p>
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
        <button type="submit">Unlock</button>
    </form>
<?php else:
    $plain = trim(strip_tags($body));
    $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
    $minutes = max(1, (int) ceil($words / 220));
?>
    <article class="page-content">
        <header class="article-head">
            <h1><?= Html::escape($title) ?></h1>
            <p class="article-meta">
                <svg class="icon" aria-hidden="true" focusable="false"><use href="#s-clock"></use></svg>
                <?= $minutes ?> min read
            </p>
        </header>
        <?= $body ?>
        <?php require __DIR__ . '/../shared/page-date.php'; ?>
    </article>
<?php endif;
