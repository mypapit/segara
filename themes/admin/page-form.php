<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var string $csrfToken
 * @var string $formAction
 * @var string $pagesUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 * @var string|null $pageId
 * @var int|null $revision
 * @var string|null $trashUrl
 * @var string|null $deleteUrl
 * @var string $title
 * @var string $slug
 * @var string $bodyMarkdown
 * @var string $description
 * @var string $videoPath
 * @var int $showContactForm
 * @var string $placement
 * @var string $state
 * @var string $schemaType
 * @var list<string> $tags
 * @var string $authorId
 * @var string $publishedAtIso
 * @var list<array{id: string, username: string, display_name: string, created_at: string}> $admins
 * @var string|null $previewUrl
 * @var string $newPageMessage
 * @var list<array{id: string, original_name: string, mime: string, alt_text: string, decorative: int}> $mediaItems
 */
$heading = $pageId === null ? 'New page' : 'Edit page';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <script src="<?= Html::escape($basePath . '/assets/admin.js') ?>" defer></script>
    <title><?= Html::escape($heading) ?></title>
</head>
<body class="admin">
<?php $current = 'pages'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow"><?= $pageId === null ? 'Create' : 'Editing' ?></span>
            <h1><?= Html::escape($heading) ?></h1>
            <p class="lede"><?= $pageId === null ? 'Only the title is required. Save a draft first if the text is not ready to publish.' : 'Saving replaces the published version. Use Preview to read it as a visitor would before you publish.' ?></p>
        </div>
        <div class="page-head__actions">
            <a class="button" href="<?= Html::escape($pagesUrl) ?>">All pages</a>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= Html::escape($formAction) ?>" data-page-form>
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
        <?php if ($pageId !== null): ?>
            <input type="hidden" name="page_id" value="<?= Html::escape($pageId) ?>">
            <input type="hidden" name="revision" value="<?= Html::escape((string) $revision) ?>">
        <?php endif; ?>

        <div class="split--wide split">
            <section class="panel" aria-labelledby="content-heading">
                <div class="panel-head">
                    <h2 id="content-heading">Content</h2>
                    <span class="muted">Markdown, rendered to semantic HTML.</span>
                </div>
                <div class="field">
                    <label for="title">Title</label>
                    <input id="title" name="title" type="text" value="<?= Html::escape($title) ?>" required maxlength="200">
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="slug">Address</label>
                        <input id="slug" name="slug" type="text" value="<?= Html::escape($slug) ?>" maxlength="200" pattern="[a-z0-9]+(?:-[a-z0-9]+)*">
                        <p class="hint">Optional. Derived from the title when empty. Lowercase words joined by dashes.</p>
                    </div>
                    <div class="field">
                        <label for="description">Summary</label>
                        <input id="description" name="description" type="text" value="<?= Html::escape($description) ?>" maxlength="500">
                        <p class="hint">Optional. Used in listings and search results.</p>
                    </div>
                </div>
                <div class="field field--wide">
                    <label for="body_markdown">Content</label>
                    <p class="hint" id="body-help">Markdown. Plain text is fine: a blank line starts a new paragraph. Maximum 1 MiB.</p>
                    <textarea id="body_markdown" name="body_markdown" rows="24" aria-describedby="body-help format-guide"><?= Html::escape($bodyMarkdown) ?></textarea>

                    <dl class="format-guide" id="format-guide">
                        <dt><code># Heading</code></dt>
                        <dd>Starts a section. Use <code>##</code> for a sub-section.</dd>
                        <dt><code>**bold**</code> <code>*italic*</code></dt>
                        <dd>Emphasis.</dd>
                        <dt><code>- item</code></dt>
                        <dd>A bulleted list.</dd>
                        <dt><code>1. item</code></dt>
                        <dd>A numbered list.</dd>
                        <dt><code>[text](https://example.test)</code></dt>
                        <dd>A link. It opens in the same tab.</dd>
                        <dt><code>![description](media.php?id=ID)</code></dt>
                        <dd>An image from the media library. The description is read aloud; leave it empty only for decoration.</dd>
                        <dt><code>`code`</code></dt>
                        <dd>Inline code. Three backticks start a block.</dd>
                        <dt><code>&gt; quote</code></dt>
                        <dd>A quotation.</dd>
                    </dl>
                    <p class="hint">Tables are removed completely, embeds are dropped, and raw HTML is shown as plain text. An image hosted elsewhere loses its address when saved.</p>
                </div>
                <?php require __DIR__ . '/partial-media-picker.php'; ?>
            </section>

            <aside>
                <section class="panel" aria-labelledby="publication-heading">
                    <div class="panel-head">
                        <h2 id="publication-heading">Publication</h2>
                    </div>
                    <div class="field">
                        <label for="state">Status</label>
                        <select id="state" name="state" aria-describedby="state-help">
                            <option value="draft"<?= $state === 'draft' ? ' selected' : '' ?>>Draft — only you can see it</option>
                            <option value="published"<?= $state === 'published' ? ' selected' : '' ?>>Published — anyone can read it</option>
                            <option value="private"<?= $state === 'private' ? ' selected' : '' ?>>Private — readers need a password</option>
                            <option value="trash"<?= $state === 'trash' ? ' selected' : '' ?>>Trash — hidden, recoverable</option>
                        </select>
                        <p class="hint" id="state-help">Draft and Trash are not visible to readers. Save and Publish overrides this menu.</p>
                    </div>
                    <div class="field">
                        <label for="password">Access password <span class="muted">(private pages only)</span></label>
                        <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" aria-describedby="password-help">
                        <p class="hint" id="password-help">8 to 4096 characters. Leave blank to keep the current password. A page that is no longer private loses its protection.</p>
                    </div>
                    <div class="field">
                        <label for="published_at">Publication date</label>
                        <input id="published_at" name="published_at" type="date" value="<?= Html::escape($publishedAtIso) ?>">
                        <p class="hint"><?= $pageId === null ? 'Leave blank to use today.' : 'Leave blank to keep the stored date.' ?> The stored time is UTC; changing only the day keeps the original time.</p>
                    </div>
                    <div class="field">
                        <label for="author_id">Author</label>
                        <select id="author_id" name="author_id">
                            <option value=""><?= $pageId === null ? '— the signed-in account —' : '— keep the current author —' ?></option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?= Html::escape($admin['id']) ?>"<?= $authorId === $admin['id'] ? ' selected' : '' ?>><?= Html::escape($admin['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">The byline is stored with the page, so removing an account later keeps the name.</p>
                    </div>
                    <div class="field">
                        <label for="tags">Tags</label>
                        <input id="tags" name="tags" type="text" value="<?= Html::escape(implode(', ', $tags)) ?>" maxlength="500">
                        <p class="hint">Comma separated, for example teaching, notes.</p>
                    </div>
                    <div class="field">
                        <label for="schema_type">Page type</label>
                        <select id="schema_type" name="schema_type">
                            <option value="WebPage"<?= $schemaType === 'WebPage' ? ' selected' : '' ?>>Standard page</option>
                            <option value="Article"<?= $schemaType === 'Article' ? ' selected' : '' ?>>Article</option>
                            <option value="ProfilePage"<?= $schemaType === 'ProfilePage' ? ' selected' : '' ?>>Profile</option>
                            <option value="ContactPage"<?= $schemaType === 'ContactPage' ? ' selected' : '' ?>>Contact</option>
                            <option value="AboutPage"<?= $schemaType === 'AboutPage' ? ' selected' : '' ?>>About</option>
                        </select>
                        <p class="hint">Used for the structured data that search engines read.</p>
                    </div>
                </section>

                <section class="panel" aria-labelledby="placement-heading">
                    <div class="panel-head">
                        <h2 id="placement-heading">Placement</h2>
                    </div>
                    <div class="field">
                        <label for="placement">Homepage</label>
                        <select id="placement" name="placement">
                            <option value="home"<?= $placement === 'home' ? ' selected' : '' ?>>List on the homepage</option>
                            <option value="featured"<?= $placement === 'featured' ? ' selected' : '' ?>>Feature prominently</option>
                            <option value="none"<?= $placement === 'none' ? ' selected' : '' ?>>Do not show on the homepage</option>
                        </select>
                        <p class="hint">Listing and featuring are separate from publication state and menu membership.</p>
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="show_contact_form" value="1"<?= $showContactForm === 1 ? ' checked' : '' ?>>
                            Show contact form on this page
                        </label>
                        <p class="hint">Messages are stored locally; the site does not send email.</p>
                    </div>
                </section>

                <section class="panel" aria-labelledby="media-heading">
                    <div class="panel-head">
                        <h2 id="media-heading">Video</h2>
                    </div>
                    <div class="field">
                        <label for="video_path">Local video path</label>
                        <input id="video_path" name="video_path" type="text" value="<?= Html::escape($videoPath) ?>" maxlength="500" placeholder="/videos/intro.mp4">
                        <p class="hint">Optional MP4 or WebM served from this site. Remote players are not embedded.</p>
                    </div>
                </section>
            </aside>
        </div>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="button">Save</button>
            <button type="submit" name="intent" value="publish" class="button button-primary" title="Publishes immediately, whatever the Status menu says.">Save and publish</button>
            <?php if ($previewUrl !== null): ?>
                <a class="button button--secondary" href="<?= Html::escape($previewUrl) ?>">Preview</a>
                <span class="hint">Only you can see the preview. It shows the saved version, so save first to see your latest edits.</span>
            <?php else: ?>
                <span class="button button--secondary is-disabled">Preview</span>
                <span class="hint"><?= Html::escape($newPageMessage) ?></span>
            <?php endif; ?>
            <span class="hint">Saving replaces the live version immediately.</span>
        </div>
    </form>

    <?php if ($pageId !== null): ?>
        <div class="danger-zone" role="group" aria-labelledby="destructive">
            <h2 id="destructive">Remove this page</h2>
            <p>Moving to trash keeps the text and takes the page off the site. Permanent deletion cannot be undone.</p>
            <div class="form-actions">
                <form method="post" action="<?= Html::escape((string) $trashUrl) ?>">
                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                    <input type="hidden" name="page_id" value="<?= Html::escape($pageId) ?>">
                    <input type="hidden" name="revision" value="<?= Html::escape((string) $revision) ?>">
                    <button type="submit" class="button">Move to trash</button>
                </form>
                <a class="button button--danger" href="<?= Html::escape((string) $deleteUrl) ?>">Delete permanently…</a>
            </div>
        </div>
    <?php endif; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
