<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * No-JS media insert. Every item exposes its snippet in data-snippet; admin.js
 * inserts it at the caret when the button is clicked, and with JS off the author
 * copies it from the visible text. The snippet is generated from the stored row,
 * so its alt text always matches the media library.
 *
 * @var list<array{id: string, original_name: string, mime: string, alt_text: string, decorative: int}> $mediaItems
 */
$images = array_values(array_filter(
    $mediaItems,
    static fn(array $item): bool => str_starts_with($item['mime'], 'image/')
));
?>
<details class="media-picker">
    <summary>Insert an image</summary>
    <?php if ($images === []): ?>
        <p class="hint">No images yet. Upload one in <a href="<?= Html::escape($basePath . '/admin.php?action=media') ?>">Media</a>, then come back here.</p>
    <?php else: ?>
        <p class="hint">Pick an image to insert it at the cursor. The snippet is shown with each image so you can copy it by hand instead.</p>
        <ul class="media-picker__grid">
            <?php foreach ($images as $item): ?>
                <?php
                $decorative = $item['decorative'] === 1;
                // An unbalanced bracket in stored alt text would end the Markdown
                // label early: ![Price ] per unit](…) renders as text, and a stray
                // '[' turns the whole thing into a link. Brackets are not part of
                // the alt an author needs, so normalise them for the snippet.
                $alt = $decorative ? '' : str_replace(['[', ']'], ['(', ')'], $item['alt_text']);
                $missingAlt = !$decorative && trim($item['alt_text']) === '';
                $snippet = '![' . $alt . '](media.php?id=' . $item['id'] . ')';
                ?>
                <li>
                    <?php if ($missingAlt): ?>
                        <div class="media-picker__item media-picker__item--warn">
                            <img src="media.php?id=<?= Html::escape($item['id']) ?>" alt="" width="96" height="96" loading="lazy">
                            <span><?= Html::escape($item['original_name']) ?></span>
                            <span class="hint">Add alt text in Media before using this image.</span>
                        </div>
                    <?php else: ?>
                        <button type="button" class="media-picker__item" data-media-insert data-snippet="<?= Html::escape($snippet) ?>">
                            <img src="media.php?id=<?= Html::escape($item['id']) ?>" alt="" width="96" height="96" loading="lazy">
                            <span><?= Html::escape($item['original_name']) ?></span>
                            <?php if ($decorative): ?>
                                <span class="hint">Decorative</span>
                            <?php endif; ?>
                        </button>
                        <?php /* The snippet sits outside the button so a keyboard user can select and copy it. */ ?>
                        <code class="media-picker__snippet"><?= Html::escape($snippet) ?></code>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</details>
