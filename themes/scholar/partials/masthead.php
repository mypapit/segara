<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * The home index heading. It states what the list is, not who the site is —
 * the brand already sits in the bar — and it carries only facts the list can
 * prove: how many entries there are and how recent the newest one is.
 *
 * @var list<array{id: string, title: string, slug: string, body_markdown: string, description: string, state: string, schema_type: string, author_id: ?string, author_name: string, published_at: ?string, created_at: string, updated_at: string, revision: int, placement: string, password_hash: ?string, buttons_json: string, video_path: ?string, tags: list<string>}> $pages
 * @var string $description
 */
$latest = 0;
foreach ($pages as $entry) {
    $stamp = strtotime((string) ($entry['published_at'] ?? $entry['created_at']));
    if ($stamp !== false && $stamp > $latest) {
        $latest = $stamp;
    }
}
?>
<section class="masthead" aria-labelledby="masthead-title">
    <h1 class="masthead__title" id="masthead-title">Recent writing</h1>
    <?php if (trim($description) !== ''): ?>
        <p class="masthead__lead"><?= Html::escape($description) ?></p>
    <?php endif; ?>
    <dl class="masthead__stats">
        <div class="masthead__stat"><dt>Entries</dt><dd><?= count($pages) ?></dd></div>
        <?php if ($latest > 0): ?>
            <div class="masthead__stat"><dt>Latest</dt><dd><?= Html::escape(gmdate('F Y', $latest)) ?></dd></div>
        <?php endif; ?>
    </dl>
</section>
