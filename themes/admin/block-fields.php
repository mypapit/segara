<?php
declare(strict_types=1);
use Segara\Blocks\BlockTypes;
use Segara\Support\Html;

/**
 * Per-type settings fields. The names must match BlockController::settingsFromPost().
 *
 * @var string $type
 * @var array<string, mixed> $settings
 * @var list<array<string, mixed>> $mediaOptions
 * @var list<array{id: string, name: string, created_at: string, updated_at: string}> $menuOptions
 */
$value = static function (string $key, string $default = '') use ($settings): string {
    $raw = $settings[$key] ?? null;

    return is_string($raw) ? $raw : $default;
};
/** @return list<array<string, string>> */
$rows = static function (string $key, array $fields) use ($settings): array {
    $raw = $settings[$key] ?? null;
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $built = [];
            foreach ($fields as $field) {
                $built[$field] = is_string($row[$field] ?? null) ? $row[$field] : '';
            }
            $out[] = $built;
        }
    }
    $blank = array_fill_keys($fields, '');
    $out[] = $blank;
    $out[] = $blank;

    return $out;
};
?>
<fieldset class="block-fieldset">
    <legend><?= Html::escape(BlockTypes::label($type)) ?></legend>
    <p class="hint"><?= Html::escape(BlockTypes::description($type)) ?></p>

    <?php if ($type === 'menu'): ?>
        <p class="field">
            <label for="bf-menu">Menu</label>
            <select id="bf-menu" name="settings[menu_id]" required>
                <option value="">— choose a menu —</option>
                <?php foreach ($menuOptions as $menu): ?>
                    <option value="<?= Html::escape($menu['id']) ?>"<?= $value('menu_id') === $menu['id'] ? ' selected' : '' ?>><?= Html::escape($menu['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

    <?php elseif ($type === 'images'): ?>
        <?php $selected = is_array($settings['image_ids'] ?? null) ? $settings['image_ids'] : []; ?>
        <p class="field">
            <label for="bf-images-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-images-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <?php if ($mediaOptions === []): ?>
            <p class="hint">No images uploaded yet. Add some in Media first.</p>
        <?php else: ?>
            <div class="field">
                <span class="field-label">Images</span>
                <ul class="media-picker__grid">
                    <?php foreach ($mediaOptions as $item): ?>
                        <?php $id = (string) $item['id']; ?>
                        <li>
                            <label class="media-option">
                                <input type="checkbox" name="settings[image_ids][]" value="<?= Html::escape($id) ?>"<?= in_array($id, $selected, true) ? ' checked' : '' ?>>
                                <img src="media.php?id=<?= Html::escape($id) ?>" alt="" width="72" height="72" loading="lazy">
                                <span><?= Html::escape((string) $item['original_name']) ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'rss'): ?>
        <p class="field">
            <label for="bf-rss">Button wording</label>
            <input id="bf-rss" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title', 'RSS feed')) ?>">
            <span class="hint">Links to /rss.xml.</span>
        </p>

    <?php elseif ($type === 'social'): ?>
        <p class="field">
            <label for="bf-social-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-social-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <?php foreach ($rows('networks', ['provider', 'url']) as $index => $row): ?>
            <p class="field field--row">
                <label for="bf-net-<?= (int) $index ?>">Network</label>
                <select id="bf-net-<?= (int) $index ?>" name="settings[networks][<?= (int) $index ?>][provider]">
                    <option value="">— none —</option>
                    <?php foreach (\Segara\Blocks\BlockRepository::SOCIAL_PROVIDERS as $provider): ?>
                        <option value="<?= Html::escape($provider) ?>"<?= $row['provider'] === $provider ? ' selected' : '' ?>><?= Html::escape(ucfirst($provider)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="bf-net-url-<?= (int) $index ?>">URL</label>
                <input id="bf-net-url-<?= (int) $index ?>" name="settings[networks][<?= (int) $index ?>][url]" type="text" maxlength="2000" value="<?= Html::escape($row['url']) ?>">
            </p>
        <?php endforeach; ?>
        <p class="hint">Leave a row blank to skip it. Up to 30 links.</p>

    <?php elseif ($type === 'external-links'): ?>
        <p class="field">
            <label for="bf-links-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-links-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <?php foreach ($rows('links', ['label', 'url']) as $index => $row): ?>
            <p class="field field--row">
                <label for="bf-link-<?= (int) $index ?>">Label</label>
                <input id="bf-link-<?= (int) $index ?>" name="settings[links][<?= (int) $index ?>][label]" type="text" maxlength="200" value="<?= Html::escape($row['label']) ?>">
                <label for="bf-link-url-<?= (int) $index ?>">URL</label>
                <input id="bf-link-url-<?= (int) $index ?>" name="settings[links][<?= (int) $index ?>][url]" type="text" maxlength="2000" value="<?= Html::escape($row['url']) ?>">
            </p>
        <?php endforeach; ?>
        <p class="hint">Up to 50 links.</p>

    <?php elseif ($type === 'quick-facts'): ?>
        <p class="field">
            <label for="bf-facts-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-facts-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <?php foreach ($rows('facts', ['text', 'bullet']) as $index => $row): ?>
            <p class="field field--row">
                <label for="bf-fact-<?= (int) $index ?>">Fact</label>
                <input id="bf-fact-<?= (int) $index ?>" name="settings[facts][<?= (int) $index ?>][text]" type="text" maxlength="200" value="<?= Html::escape($row['text']) ?>">
                <label for="bf-fact-bullet-<?= (int) $index ?>">Bullet</label>
                <input id="bf-fact-bullet-<?= (int) $index ?>" name="settings[facts][<?= (int) $index ?>][bullet]" type="text" maxlength="4" value="<?= Html::escape($row['bullet']) ?>">
            </p>
        <?php endforeach; ?>
        <p class="hint">One fact per row. The bullet is optional — a character such as ★ or a dash.</p>

    <?php elseif ($type === 'custom-inline'): ?>
        <p class="field">
            <label for="bf-custom-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-custom-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <p class="field">
            <label for="bf-custom-html">Text</label>
            <textarea id="bf-custom-html" name="settings[html]" rows="3"><?= Html::escape($value('html')) ?></textarea>
            <span class="hint">Simple emphasis and links only; scripts and block markup are removed.</span>
        </p>

    <?php elseif ($type === 'copyright' || $type === 'address'): ?>
        <p class="field">
            <label for="bf-text"><?= $type === 'copyright' ? 'Copyright wording' : 'Address' ?></label>
            <textarea id="bf-text" name="settings[text]" rows="3"><?= Html::escape($value('text')) ?></textarea>
            <span class="hint"><?= $type === 'copyright' ? 'Replaces the generated copyright notice.' : 'Line breaks are preserved.' ?></span>
        </p>

    <?php elseif ($type === 'blurb'): ?>
        <p class="field">
            <label for="bf-blurb-title">Heading <span class="hint">(optional)</span></label>
            <input id="bf-blurb-title" name="settings[title]" type="text" maxlength="200" value="<?= Html::escape($value('title')) ?>">
        </p>
        <p class="field">
            <label for="bf-blurb-text">Biography</label>
            <textarea id="bf-blurb-text" name="settings[text]" rows="3" required><?= Html::escape($value('text')) ?></textarea>
        </p>
        <p class="field">
            <label for="bf-blurb-image">Portrait <span class="hint">(optional)</span></label>
            <select id="bf-blurb-image" name="settings[image_id]">
                <option value="">— none —</option>
                <?php foreach ($mediaOptions as $item): ?>
                    <option value="<?= Html::escape((string) $item['id']) ?>"<?= $value('image_id') === (string) $item['id'] ? ' selected' : '' ?>><?= Html::escape((string) $item['original_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">An image without a description is left out rather than shown undescribed.</span>
        </p>
    <?php endif; ?>
</fieldset>
