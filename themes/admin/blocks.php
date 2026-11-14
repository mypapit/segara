<?php
declare(strict_types=1);
use Segara\Blocks\BlockTypes;
use Segara\Support\Html;

/**
 * @var array{left: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, right: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>, footer: list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}>} $blocksByLocation
 * @var array<string, string> $previews
 * @var string $csrfToken
 * @var string $reorderUrl
 * @var string $createUrl
 * @var string $deleteUrl
 * @var string $confirmDeleteUrl
 * @var string $editUrl
 * @var array<string, array{label: string, description: string, locations: list<string>}> $types
 * @var list<array<string, mixed>> $mediaOptions
 * @var list<array{id: string, name: string, created_at: string, updated_at: string}> $menuOptions
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 * @var string|null $notice
 * @var array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}|null $editing
 * @var string|null $selectedType
 * @var string|null $selectedLocation
 * @var array<string, mixed>|null $submittedSettings
 */
$locations = ['left', 'right', 'footer'];
$locationLabel = static fn(string $location): string => match ($location) {
    'left' => 'First sidebar',
    'right' => 'Second sidebar',
    default => 'Footer',
};
$defaultTypeFor = static function (string $location): string {
    $allowed = BlockTypes::forLocation($location);
    if ($location === 'footer' && in_array('copyright', $allowed, true)) {
        return 'copyright';
    }

    return $allowed[0] ?? 'quick-facts';
};
$locationHint = static fn(string $location): string => match ($location) {
    // The column is named by order, not by side: a one-sidebar theme puts this
    // column on the right of the article, a two-sidebar theme on the left.
    'left' => 'Rendered beside the article: on the right in a one-sidebar theme, on the left in a two-sidebar theme, below it on a phone. Themes without a sidebar hide this column.',
    'right' => 'The second sidebar, on the right. Only themes that declare two sidebars show it.',
    default => 'Arranged horizontally along the bottom of every page.',
};

// The controller owns the request: no superglobals in a view (MASTER-UI §4.1).
$selectedLocation = in_array($selectedLocation, $locations, true) ? $selectedLocation : 'left';
if (!isset($selectedType) || !isset($types[$selectedType]) || !BlockTypes::allows($selectedType, $selectedLocation)) {
    // Keep the chosen column and pick a type that belongs there, instead of
    // silently sending the administrator back to the left sidebar.
    $selectedType = $defaultTypeFor($selectedLocation);
}
$allowedTypes = BlockTypes::forLocation($selectedLocation);

$type = $editing !== null ? $editing['type'] : $selectedType;
$settings = [];
if ($editing !== null) {
    $decoded = json_decode($editing['settings_json'], true);
    $settings = is_array($decoded) ? $decoded : [];
}
if (is_array($submittedSettings ?? null) && $submittedSettings !== []) {
    $settings = $submittedSettings;
}
$totalBlocks = count($blocksByLocation['left']) + count($blocksByLocation['right']) + count($blocksByLocation['footer']);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Blocks</title>
</head>
<body class="admin">
<?php $current = 'settings'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Appearance</span>
            <h1>Sidebar and footer blocks</h1>
            <p class="lede">Reusable panels placed outside the page text. Order is saved per location; blocks are kept when a theme hides a column.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain"><?= $totalBlocks ?> block<?= $totalBlocks === 1 ? '' : 's' ?></span>
            <a class="button" href="<?= Html::escape($basePath . '/admin.php?action=settings') ?>">Back to settings</a>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>
    <?php if ($notice === 'saved'): ?>
        <p role="status" class="notice">Block saved.</p>
    <?php endif; ?>

    <?php if ($editing !== null): ?>
        <section class="panel" aria-labelledby="edit-block">
            <div class="panel-head">
                <h2 id="edit-block">Edit <?= Html::escape(BlockTypes::label($editing['type'])) ?></h2>
                <a class="button button--sm" href="<?= Html::escape($basePath . '/admin.php?action=blocks') ?>">Done</a>
            </div>
            <form method="post" action="<?= Html::escape($editUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <input type="hidden" name="block_id" value="<?= Html::escape($editing['id']) ?>">
                <input type="hidden" name="revision" value="<?= (int) $editing['revision'] ?>">
                <input type="hidden" name="type" value="<?= Html::escape($editing['type']) ?>">
                <div class="field">
                    <label for="edit-location">Location</label>
                    <select id="edit-location" name="location">
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= Html::escape($location) ?>"<?= $editing['location'] === $location ? ' selected' : '' ?>><?= Html::escape($locationLabel($location)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php require __DIR__ . '/block-fields.php'; ?>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Save block</button>
                    <a class="button" href="<?= Html::escape($basePath . '/admin.php?action=blocks') ?>">Cancel</a>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel" aria-labelledby="new-block">
            <div class="panel-head">
                <h2 id="new-block">Add a block</h2>
                <span class="muted">Choose where it lives and what it shows, then fill in its settings.</span>
            </div>
            <form method="get" action="<?= Html::escape($basePath . '/admin.php') ?>">
                <input type="hidden" name="action" value="blocks">
                <div class="grid-2">
                    <div class="field">
                        <label for="block-location">Where</label>
                        <select id="block-location" name="location">
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= Html::escape($location) ?>"<?= $selectedLocation === $location && $editing === null ? ' selected' : '' ?>><?= Html::escape($locationLabel($location)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="block-type">What</label>
                        <select id="block-type" name="type">
                            <?php foreach ($allowedTypes as $key): ?>
                                <?php $meta = $types[$key] ?? null; ?>
                                <?php if ($meta === null) { continue; } ?>
                                <option value="<?= Html::escape($key) ?>"<?= $selectedType === $key ? ' selected' : '' ?>><?= Html::escape($meta['label']) ?> — <?= Html::escape($meta['description']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">Choose</button>
                    <span class="hint">The next screen shows only the settings this type needs.</span>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php foreach ($locations as $location): ?>
        <?php $blocks = $blocksByLocation[$location]; $ids = array_map(static fn(array $b): string => $b['id'], $blocks); $count = count($blocks); ?>
        <section class="panel" aria-labelledby="blocks-<?= Html::escape($location) ?>">
            <div class="panel-head">
                <h2 id="blocks-<?= Html::escape($location) ?>"><?= Html::escape($locationLabel($location)) ?></h2>
                <span class="chip chip--plain"><?= $count ?></span>
            </div>
            <p class="block-location-note"><?= Html::escape($locationHint($location)) ?></p>
            <?php if ($count === 0): ?>
                <div class="empty-state">
                    <strong>Empty column.</strong>
                    Add a block above and it will appear here in this position.
                </div>
            <?php else: ?>
                <?php /* A container, not a form: the arrow buttons are their own forms, and a
                         <form> inside a <form> is dropped by the HTML parser. */ ?>
                <div class="block-order" data-location="<?= Html::escape($location) ?>">
                    <ol class="block-order-list">
                        <?php foreach ($blocks as $i => $block): ?>
                            <li class="block-order-item" draggable="true" data-id="<?= Html::escape($block['id']) ?>">
                                <span class="block-drag-handle" aria-hidden="true">&#9776;</span>
                                <div class="block-preview"><?= $previews[$block['id']] ?></div>
                                <span class="block-meta"><?= Html::escape(BlockTypes::label($block['type'])) ?> · #<?= (int) $block['position'] ?></span>
                                <span class="block-controls">
                                    <a class="button button--sm" href="<?= Html::escape($editUrl . '&id=' . $block['id']) ?>">Edit</a>
                                    <?php if ($i > 0): ?>
                                        <?php $up = $ids; $swap = $up[$i - 1]; $up[$i - 1] = $up[$i]; $up[$i] = $swap; ?>
                                        <form method="post" action="<?= Html::escape($reorderUrl) ?>" class="inline-form">
                                            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                            <input type="hidden" name="location" value="<?= Html::escape($location) ?>">
                                            <input type="hidden" name="order" value="<?= Html::escape(json_encode($up, JSON_THROW_ON_ERROR)) ?>">
                                            <button type="submit" aria-label="Move up">↑</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($i < $count - 1): ?>
                                        <?php $down = $ids; $swap = $down[$i + 1]; $down[$i + 1] = $down[$i]; $down[$i] = $swap; ?>
                                        <form method="post" action="<?= Html::escape($reorderUrl) ?>" class="inline-form">
                                            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                            <input type="hidden" name="location" value="<?= Html::escape($location) ?>">
                                            <input type="hidden" name="order" value="<?= Html::escape(json_encode($down, JSON_THROW_ON_ERROR)) ?>">
                                            <button type="submit" aria-label="Move down">↓</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="button button--sm button--danger" href="<?= Html::escape($confirmDeleteUrl . '&id=' . $block['id']) ?>">Delete</a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <form method="post" action="<?= Html::escape($reorderUrl) ?>" class="block-order-save">
                        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                        <input type="hidden" name="location" value="<?= Html::escape($location) ?>">
                        <input type="hidden" name="order" class="js-block-order" value="<?= Html::escape(json_encode($ids, JSON_THROW_ON_ERROR)) ?>">
                        <div class="form-actions">
                            <button type="submit" class="button button--sm">Save order</button>
                            <span class="hint">Drag a row by its handle, then save. The arrows work without JavaScript.</span>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($editing === null): ?>
        <section class="panel" aria-labelledby="create-block">
            <div class="panel-head">
                <h2 id="create-block">New <?= Html::escape(BlockTypes::label($type)) ?></h2>
                <span class="muted"><?= Html::escape($locationLabel($selectedLocation)) ?></span>
            </div>
            <form method="post" action="<?= Html::escape($createUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <input type="hidden" name="type" value="<?= Html::escape($type) ?>">
                <input type="hidden" name="location" value="<?= Html::escape($selectedLocation) ?>">
                <?php require __DIR__ . '/block-fields.php'; ?>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Create block</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
<script src="<?= Html::escape($basePath . '/assets/block-order.js') ?>"></script>
</body>
</html>
