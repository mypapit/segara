<?php

declare(strict_types=1);

/**
 * Slate's site identity band lives in layout.php. This template owns only the
 * published-page collection and its honest, list-derived count.
 *
 * @var list<array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>}> $pages
 * @var string $basePath
 */
?>
<?php if (count($pages) === 0): ?>
    <div class="empty-state">
        <svg class="icon empty-state__icon" aria-hidden="true" focusable="false"><use href="#s-book"></use></svg>
        <p>No published content yet.</p>
    </div>
<?php else: ?>
    <section class="collection" aria-labelledby="collection-title">
        <div class="collection-head">
            <div>
                <p class="collection-head__eyebrow">The index</p>
                <h2 id="collection-title">Latest pages</h2>
            </div>
            <p class="collection-head__count"><?= count($pages) ?> <?= count($pages) === 1 ? 'page' : 'pages' ?></p>
        </div>
        <div class="page-stream">
            <?php foreach ($pages as $page): ?>
                <?php $featured = $page['placement'] === 'featured'; ?>
                <?php require __DIR__ . '/partials/page-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
