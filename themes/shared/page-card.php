<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * One entry in the index, for the themes that have not grown their own card.
 * Folio renders this one; clarity, aubergine, editorial and slate each carry a
 * `partials/page-card.php` with the facts their stream shows.
 *
 * @var array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>} $page
 * @var bool $featured
 * @var string $basePath
 */
$url = $basePath . '/' . $page['slug'];
?>
<article class="page-card<?= $featured ? ' page-card--featured' : '' ?>">
    <h2>
        <?php if ($featured): ?><span class="badge">Featured</span> <?php endif; ?>
        <a href="<?= Html::escape($url) ?>"><?= Html::escape($page['title']) ?></a>
    </h2>
    <?php if ($page['description'] !== ''): ?>
        <p><?= Html::escape($page['description']) ?></p>
    <?php endif; ?>
    <?php if ($featured): ?>
        <p class="prominent"><a class="button" href="<?= Html::escape($url) ?>">Read more</a></p>
    <?php endif; ?>
</article>
