<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * The publication date under an article. `<time datetime>` carries the machine
 * readable date even when the visible text is a relative phrase.
 *
 * @var array{iso: string, text: string}|null $page_date
 */
?>
<?php if (($page_date ?? null) !== null): ?>
    <p class="page-meta"><time class="page-meta__date" datetime="<?= Html::escape($page_date['iso']) ?>"><?= Html::escape($page_date['text']) ?></time></p>
<?php endif; ?>
