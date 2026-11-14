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

/* Folio opened straight into its entries, which left the document without a
   heading and the list without a title. The colophon-style head below names the
   folio and carries the two counts the list can prove. */
?>
<div class="folio-head">
    <h1 class="folio-head__title"><?= Html::escape($site_name) ?></h1>
    <?php if (trim($site_tagline) !== ''): ?>
        <p class="folio-head__lead"><?= Html::escape($site_tagline) ?></p>
    <?php endif; ?>
    <?php if ($latest > 0 || count($pages) > 1): ?>
        <dl class="folio-head__facts">
            <?php if (count($pages) > 1): ?>
                <div class="folio-head__fact"><dt>Entries</dt><dd><?= count($pages) ?></dd></div>
            <?php endif; ?>
            <?php if ($latest > 0): ?>
                <div class="folio-head__fact"><dt>Latest</dt><dd><?= Html::escape(gmdate('F Y', $latest)) ?></dd></div>
            <?php endif; ?>
        </dl>
    <?php endif; ?>
</div>
<?php
if (count($pages) === 0): ?>
    <p class="empty-state">No published content yet.</p>
<?php else: ?>
    <div class="page-stream">
        <?php foreach ($pages as $page): ?>
            <?php $featured = $page['placement'] === 'featured'; ?>
            <?php require __DIR__ . '/partials/page-card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif;
