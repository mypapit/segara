<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string $title @var string $body @var bool $challenge @var string $csrfToken @var string|null $challengeError */
if ($challenge): ?>
    <form method="post" class="challenge" action="">
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
        <?php /* The heading carries its own page: the reading time sits below it
                 with the other facts about the article, never as a label above. */ ?>
        <header class="article-head">
            <svg class="register-mark" aria-hidden="true" focusable="false"><use href="#s-register"></use></svg>
            <h1><?= Html::escape($title) ?></h1>
            <p class="article-meta">
                <svg class="icon" aria-hidden="true" focusable="false"><use href="#s-clock"></use></svg>
                <?= $minutes ?> min read
            </p>
        </header>
        <?= $body ?>
        <?php require __DIR__ . '/../shared/page-date.php'; ?>
    </article>
<?php endif; ?>
