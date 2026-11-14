<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, original_name: string, stored_path: string, mime: string, size: int, alt_text: string, decorative: int, created_at: string, updated_at: string}> $media
 * @var string $csrfToken
 * @var string $uploadUrl
 * @var string $deleteUrl
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string $mediaUrlPrefix
 * @var string|null $error
 */
$humanSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
};
$typeLabel = static function (string $mime): string {
    return match ($mime) {
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        default => $mime,
    };
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Media</title>
</head>
<body class="admin">
<?php $current = 'media'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Library</span>
            <h1>Media</h1>
            <p class="lede">Uploaded images, with the alt text and Markdown snippet each page needs.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain"><?= count($media) ?> image<?= count($media) === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <section class="panel" aria-labelledby="upload">
        <div class="panel-head">
            <h2 id="upload">Upload an image</h2>
            <span class="muted">JPEG, PNG, or WebP · up to 5 MiB</span>
        </div>
        <form method="post" action="<?= Html::escape($uploadUrl) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
            <div class="grid-2">
                <div class="field">
                    <label for="image">Image file</label>
                    <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required>
                    <p class="hint">The original filename is kept as a label. The stored name is generated.</p>
                </div>
                <div class="field">
                    <label for="alt_text">Alt text</label>
                    <input id="alt_text" name="alt_text" type="text" maxlength="500">
                    <p class="hint">Describe what the image shows. Leave empty only when it is decorative.</p>
                </div>
            </div>
            <div class="field">
                <label class="checkbox">
                    <input id="decorative" name="decorative" type="checkbox" value="1">
                    Decorative image, no description needed
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="button button-primary">Upload</button>
            </div>
        </form>
    </section>

    <div class="panel-head">
        <h2 id="library">Library</h2>
        <span class="muted">Deleting an image used by a page leaves that page with a broken reference.</span>
    </div>
    <?php if (count($media) === 0): ?>
        <div class="empty-state">
            <strong>No images yet.</strong>
            Upload the first one above, then insert it into a page with the snippet shown beside it.
        </div>
    <?php else: ?>
        <ul class="media-grid" aria-labelledby="library">
            <?php foreach ($media as $item): ?>
                <?php
                    $url = $mediaUrlPrefix . $item['id'];
                    $snippet = '![' . $item['alt_text'] . '](' . $url . ')';
                ?>
                <li class="media-card">
                    <a class="media-card__thumb" href="<?= Html::escape($url) ?>">
                        <img src="<?= Html::escape($url) ?>" alt="" loading="lazy" width="320" height="240">
                    </a>
                    <div class="media-card__body">
                        <span class="media-card__name"><?= Html::escape($item['original_name']) ?></span>
                        <span class="media-card__meta">
                            <span class="chip chip--plain"><?= Html::escape($typeLabel($item['mime'])) ?></span>
                            <span class="chip chip--plain"><?= Html::escape($humanSize($item['size'])) ?></span>
                            <?php if ($item['decorative'] === 1): ?>
                                <span class="chip chip--plain">Decorative</span>
                            <?php endif; ?>
                        </span>
                        <?php if ($item['alt_text'] !== ''): ?>
                            <p class="media-card__alt"><?= Html::escape($item['alt_text']) ?></p>
                        <?php else: ?>
                            <p class="media-card__alt muted">No alt text.</p>
                        <?php endif; ?>
                        <div class="snippet">
                            <label for="snippet-<?= Html::escape($item['id']) ?>">Markdown snippet</label>
                            <input id="snippet-<?= Html::escape($item['id']) ?>" type="text" readonly value="<?= Html::escape($snippet) ?>">
                            <button type="button" data-copy="<?= Html::escape($snippet) ?>">Copy</button>
                        </div>
                        <div class="media-card__foot">
                            <a class="button button--sm button--ghost" href="<?= Html::escape($url) ?>">Open full size</a>
                            <details class="menu-details menu-details--danger">
                                <summary>Delete</summary>
                                <form method="post" action="<?= Html::escape($deleteUrl) ?>">
                                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                    <input type="hidden" name="media_id" value="<?= Html::escape($item['id']) ?>">
                                    <p class="hint">Removes the file from the library. Pages that reference it will show a broken image.</p>
                                    <button type="submit" class="button button--danger button--sm">Delete image</button>
                                </form>
                            </details>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
<script src="<?= Html::escape($basePath . '/assets/admin.js') ?>"></script>
</body>
</html>
