<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * One entry in the folio index: the date is a marginal note in the serif, the
 * title is the entry, and the tags sit under it in the small caps the theme
 * already uses for its labels.
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
    <?php if ($featured || $stamp !== false): ?>
        <div class="page-card__top">
            <?php if ($featured): ?><span class="page-card__flag">Featured</span><?php endif; ?>
            <?php if ($stamp !== false): ?>
                <time class="page-card__date" datetime="<?= Html::escape(gmdate('Y-m-d', $stamp)) ?>"><?= Html::escape(gmdate('j F Y', $stamp)) ?></time>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <h2 class="page-card__title"><a href="<?= Html::escape($url) ?>"><?= Html::escape($page['title']) ?></a></h2>
    <?php if ($page['description'] !== ''): ?>
        <p class="page-card__summary"><?= Html::escape($page['description']) ?></p>
    <?php endif; ?>
    <?php if ($tags !== []): ?>
        <ul class="page-card__tags">
            <?php foreach ($tags as $tag): ?><li class="page-card__tag"><?= Html::escape($tag) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <span class="page-card__more" aria-hidden="true">Read on<svg class="page-card__rule" aria-hidden="true"></svg></span>
</article>
