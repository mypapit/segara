<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>}> $pages
 * @var string $basePath
 * @var string $heroTitle
 * @var string $heroLead
 * @var string $site_name
 * @var string $site_tagline
 */
require __DIR__ . '/../shared/hero.php';

$latest = 0;
foreach ($pages as $entry) {
    $stamp = strtotime((string) ($entry['published_at'] ?? $entry['created_at']));
    if ($stamp !== false && $stamp > $latest) {
        $latest = $stamp;
    }
}

/* Clarity opened its index with nothing but the first card, which left the
   document without a heading and the stream without a lead. The site name
   carries the Display role here; the tagline and the two counts are the facts
   that belong to the page rather than to an entry. */
?>
<div class="index-head">
    <h1 class="index-head__title"><?= Html::escape($site_name) ?></h1>
    <?php if (trim($site_tagline) !== ''): ?>
        <p class="index-head__lead"><?= Html::escape($site_tagline) ?></p>
    <?php endif; ?>
    <?php if ($latest > 0 || count($pages) > 1): ?>
        <dl class="index-head__stats">
            <?php if (count($pages) > 1): ?>
                <div class="index-head__stat"><dt>Entries</dt><dd><?= count($pages) ?></dd></div>
            <?php endif; ?>
            <?php if ($latest > 0): ?>
                <div class="index-head__stat"><dt>Latest</dt><dd><?= Html::escape(gmdate('F Y', $latest)) ?></dd></div>
            <?php endif; ?>
        </dl>
    <?php endif; ?>
</div>
<?php
if (count($pages) === 0): ?>
    <div class="empty-state">
        <svg class="icon empty-state__icon" aria-hidden="true" focusable="false"><use href="#s-book"></use></svg>
        <p>No published content yet.</p>
    </div>
<?php else: ?>
    <div class="page-stream">
        <?php foreach ($pages as $page): ?>
            <?php $featured = $page['placement'] === 'featured'; ?>
            <?php require __DIR__ . '/partials/page-card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif;
