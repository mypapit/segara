<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>}> $pages
 * @var string $basePath
 * @var string $heroTitle
 * @var string $heroLead
 * @var string $description
 * @var string $site_tagline
 */
if ($heroTitle !== '') {
    require __DIR__ . '/../shared/hero.php';
} else {
    require __DIR__ . '/partials/masthead.php';
}
?>
<?php if (count($pages) === 0): ?>
    <div class="empty-state">
        <svg class="icon empty-state__icon" aria-hidden="true" focusable="false"><use href="#s-book"></use></svg>
        <p>No published content yet.</p>
    </div>
<?php else: ?>
    <div class="page-stream">
        <?php $index = 0; ?>
        <?php foreach ($pages as $page): ?>
            <?php $index++; ?>
            <?php $featured = $page['placement'] === 'featured'; ?>
            <?php require __DIR__ . '/partials/page-card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif;
