<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{icon: string, label: string, target_type: string, target: string}> $buttons
 * @var string|null $videoPath
 * @var string $basePath
 */
if ($videoPath !== null && $videoPath !== ''): ?>
    <video controls preload="none" src="<?= Html::escape($basePath . $videoPath) ?>"></video>
<?php endif; ?>
<?php if (count($buttons) > 0): ?>
    <nav class="page-actions" aria-label="Page actions">
        <?php foreach ($buttons as $button): ?>
            <?php
                $url = match ($button['target_type']) {
                    'custom' => str_starts_with($button['target'], '/') ? $basePath . $button['target'] : $button['target'],
                    'document' => $basePath . $button['target'],
                    'media' => $basePath . '/media.php?id=' . $button['target'],
                    'menu' => '#menu-item-' . $button['target'],
                    default => '#',
                };
            ?>
            <a class="button" href="<?= Html::escape($url) ?>">
                <span class="icon icon-<?= Html::escape($button['icon']) ?>" aria-hidden="true"></span>
                <?= Html::escape($button['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif;
