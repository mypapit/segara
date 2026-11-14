<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var array<string, string> $values
 * @var list<array{id: string, name: string, sidebars: int, description: string, swatch: array{surface: string, primary: string, accent: string}}> $themeOptions
 * @var list<array{id: string, title: string, slug: string, state: string, updated_at: string, revision: int}> $pages
 * @var string $csrfToken
 * @var string $settingsUrl
 * @var string $formAction
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string $canonicalBase
 * @var string $viewSiteUrl
 * @var string $dateExample
 * @var string|null $error
 */
$currentTheme = $values['theme'] ?? 'clarity';
$sidebarLabel = static function (int $count): string {
    return match ($count) {
        0 => 'No sidebar',
        1 => 'One sidebar',
        default => 'Two sidebars',
    };
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Settings</title>
</head>
<body class="admin">
<?php $current = 'settings'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Configuration</span>
            <h1>Settings</h1>
            <p class="lede">Identity, appearance, and how dates and numbers read. These apply to every public page.</p>
        </div>
        <div class="page-head__actions">
            <a class="button" href="<?= Html::escape($viewSiteUrl) ?>" target="_blank" rel="noopener">View site</a>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <div class="split--wide split">
        <form method="post" action="<?= Html::escape($formAction) ?>" class="settings-form">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">

            <section class="panel" aria-labelledby="identity-heading">
                <div class="panel-head">
                    <h2 id="identity-heading">Site identity</h2>
                    <span class="muted">Shown in the header, browser tab, and link previews.</span>
                </div>
                <div class="field">
                    <label for="site-name">Site name</label>
                    <input id="site-name" name="site_name" type="text" required maxlength="300" aria-describedby="site-name-hint" value="<?= Html::escape($values['site_name'] ?? '') ?>">
                    <p class="hint" id="site-name-hint">The name in the header, the browser tab, and link previews.</p>
                </div>
                <div class="field">
                    <label for="site-tagline">Site tagline</label>
                    <input id="site-tagline" name="site_tagline" type="text" maxlength="300" aria-describedby="site-tagline-hint" value="<?= Html::escape($values['site_tagline'] ?? '') ?>">
                    <p class="hint" id="site-tagline-hint">Optional. One short line under the site name, for example in the home page banner.</p>
                </div>
                <div class="field">
                    <label for="site-description">Site description</label>
                    <textarea id="site-description" name="site_description" rows="2" maxlength="1000" aria-describedby="site-description-hint"><?= Html::escape($values['site_description'] ?? '') ?></textarea>
                    <p class="hint" id="site-description-hint">Optional. Used for search results and social previews.</p>
                </div>
            </section>

            <section class="panel" aria-labelledby="appearance-heading">
                <div class="panel-head">
                    <h2 id="appearance-heading">Appearance</h2>
                    <span class="muted">Applies to the public site as soon as you save.</span>
                </div>
                <div class="field">
                    <span class="field-label" id="theme-label">Theme</span>
                    <fieldset class="theme-grid" aria-labelledby="theme-label">
                        <?php foreach ($themeOptions as $option): ?>
                            <?php $selected = $currentTheme === $option['id']; ?>
                            <label class="theme-card<?= $selected ? ' is-selected' : '' ?>">
                                <input type="radio" name="theme" value="<?= Html::escape($option['id']) ?>"<?= $selected ? ' checked' : '' ?>>
                                <span class="theme-card__flag">Selected</span>
                                <span class="theme-preview theme-preview--<?= Html::escape($option['id']) ?>" aria-hidden="true">
                                    <span class="tp-chrome"><span></span><span></span><span></span></span>
                                    <span class="tp-head">
                                        <span class="tp-logo"></span>
                                        <span class="tp-nav"></span>
                                        <span class="tp-nav"></span>
                                        <span class="tp-nav short"></span>
                                    </span>
                                    <span class="tp-body">
                                        <span class="tp-side tp-side--l"></span>
                                        <span class="tp-page">
                                            <span class="tp-hero"></span>
                                            <span class="tp-line"></span>
                                            <span class="tp-line short"></span>
                                            <span class="tp-cards"><span></span><span></span></span>
                                        </span>
                                        <span class="tp-side tp-side--r"></span>
                                    </span>
                                    <span class="tp-foot"></span>
                                </span>
                                <span class="theme-card__body">
                                    <span class="theme-card__name"><?= Html::escape($option['name']) ?></span>
                                    <span class="theme-card__desc"><?= Html::escape($option['description']) ?></span>
                                    <span class="theme-card__meta">
                                        <span class="chip chip--plain"><?= Html::escape($sidebarLabel($option['sidebars'])) ?></span>
                                        <span class="chip chip--plain"><?= Html::escape($option['id']) ?></span>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="hint">Clarity Canvas has no sidebar. The one-sidebar themes, Aubergine Atelier and Warm Scholarly Modernism, show the first column beside the article, on its right. Warm Editorial shows both columns, the first on the left and the second on the right. Blocks are kept in every case and return when you switch back.</p>
                </div>
                <div class="field">
                    <label for="homepage">Homepage</label>
                    <select id="homepage" name="homepage_id">
                        <option value=""<?= ($values['homepage_id'] ?? '') === '' ? ' selected' : '' ?>>Listing of published pages</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= Html::escape($page['id']) ?>"<?= ($values['homepage_id'] ?? '') === $page['id'] ? ' selected' : '' ?>><?= Html::escape($page['title']) ?> (<?= Html::escape($page['slug']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Pick a single page to replace the listing, or keep the listing. Only pages placed on the homepage appear here.</p>
                </div>
            </section>

            <section class="panel" aria-labelledby="regional-heading">
                <div class="panel-head">
                    <h2 id="regional-heading">Regional display</h2>
                    <span class="muted">Formatting only — the site stays single-language.</span>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="locale">Language / locale</label>
                        <input id="locale" name="locale" type="text" required maxlength="35" value="<?= Html::escape($values['locale'] ?? '') ?>" placeholder="en-MY">
                        <p class="hint">For example <code>en</code>, <code>ms</code> or <code>en-MY</code>.</p>
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <input id="timezone" name="timezone" type="text" required maxlength="64" value="<?= Html::escape($values['timezone'] ?? '') ?>" placeholder="Asia/Kuala_Lumpur">
                        <p class="hint">For example <code>Asia/Kuala_Lumpur</code>.</p>
                    </div>
                    <div class="field">
                        <label for="currency">Currency</label>
                        <input id="currency" name="currency" type="text" required maxlength="3" pattern="[A-Z]{3}" value="<?= Html::escape($values['currency'] ?? '') ?>" placeholder="MYR">
                        <p class="hint">Three-letter ISO code, e.g. <code>MYR</code>. Used for formatting, not selling.</p>
                    </div>
                    <div class="field">
                        <label for="page-date-format">Date under each page</label>
                        <select id="page-date-format" name="page_date_format" aria-describedby="page-date-hint">
                            <?php foreach ([
                                'full' => 'October 25, 2026 (Sunday)',
                                'plain' => 'October 25, 2026',
                                'custom' => 'Custom pattern',
                                'relative' => 'Relative: 2 days ago',
                            ] as $dateValue => $dateLabel): ?>
                                <option value="<?= Html::escape($dateValue) ?>"<?= ($values['page_date_format'] ?? 'full') === $dateValue ? ' selected' : '' ?>><?= Html::escape($dateLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint" id="page-date-hint">Shown at the bottom of every page, in a small face.</p>
                    </div>
                </div>
                <div class="field">
                    <label for="page-date-pattern">Custom pattern</label>
                    <input id="page-date-pattern" name="page_date_pattern" type="text" maxlength="64" aria-describedby="page-date-pattern-hint" value="<?= Html::escape($values['page_date_pattern'] ?? 'F j, Y (l)') ?>">
                    <p class="hint" id="page-date-pattern-hint">Used only when Custom is selected. Tokens: <code>j</code> day, <code>F</code> month name, <code>Y</code> year, <code>l</code> weekday. Example: <code>j F Y</code> gives 25 October 2026.</p>
                </div>
                <p class="sample">Sample display: <strong><?= Html::escape($dateExample) ?></strong> · <strong>1,234.50</strong> · <strong>MYR 12.00</strong></p>
            </section>

            <div class="form-actions form-actions--sticky">
                <button type="submit" class="button button-primary">Save settings</button>
                <span class="hint">Changes to the theme and identity are visible on the public site straight away.</span>
            </div>
        </form>

        <aside>
            <section class="panel" aria-labelledby="tools-heading">
                <div class="panel-head">
                    <h2 id="tools-heading">Related tools</h2>
                </div>
                <ul class="link-list">
                    <li><a href="<?= Html::escape($basePath . '/admin.php?action=blocks') ?>">Sidebar and footer blocks</a></li>
                    <li><a href="<?= Html::escape($basePath . '/admin.php?action=transfer') ?>">Import and export content</a></li>
                    <li><a href="<?= Html::escape($basePath . '/admin.php?action=messages') ?>">Contact form messages</a></li>
                    <li><a href="<?= Html::escape($accountsUrl) ?>">Administrator accounts</a></li>
                </ul>
            </section>

            <section class="panel panel-muted" aria-labelledby="canonical-heading">
                <div class="panel-head">
                    <h2 id="canonical-heading">Canonical address</h2>
                </div>
                <p><code><?= Html::escape($canonicalBase) ?></code></p>
                <p class="hint">Set in <code>settings/config.php</code>, not editable here. It must match the address visitors use.</p>
            </section>
        </aside>
    </div>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
