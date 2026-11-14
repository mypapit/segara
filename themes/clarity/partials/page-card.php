<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * One entry in the index. The title link carries a full-card overlay, so a
 * card is one tab stop rather than three; the arrow is decoration.
 *
 * @var array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>} $page
 * @var bool $featured
 * @var string $basePath
 */
$url = $basePath . '/' . $page['slug'];
$stamp = strtotime((string) ($page['published_at'] ?? $page['created_at']));
$tags = array_slice($page['tags'], 0, 3);
?>
<article class="page-card<?= $featured ? ' page-card--featured' : '' ?>">
    <svg class="register-mark" aria-hidden="true" focusable="false"><use href="#s-register"></use></svg>
    <div class="page-card__top">
        <?php if ($featured): ?><span class="badge">Featured</span><?php endif; ?>
        <?php if ($stamp !== false): ?>
            <time class="page-card__date" datetime="<?= Html::escape(gmdate('Y-m-d', $stamp)) ?>"><?= Html::escape(gmdate('j M Y', $stamp)) ?></time>
        <?php endif; ?>
    </div>
    <h2 class="page-card__title"><a href="<?= Html::escape($url) ?>"><?= Html::escape($page['title']) ?></a></h2>
    <?php if ($page['description'] !== ''): ?>
        <p class="page-card__summary"><?= Html::escape($page['description']) ?></p>
    <?php endif; ?>
    <?php if ($tags !== []): ?>
        <ul class="page-card__tags">
            <?php foreach ($tags as $tag): ?><li class="page-card__tag"><?= Html::escape($tag) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <span class="page-card__more" aria-hidden="true">Read <svg class="icon" aria-hidden="true" focusable="false"><use href="#s-arrow"></use></svg></span>
</article>
