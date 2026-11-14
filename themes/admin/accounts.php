<?php

declare(strict_types=1);

use Segara\Support\Html;

/**
 * @var list<array{id: string, username: string, display_name: string, created_at: string, updated_at: string}> $administrators
 * @var string $currentAdminId
 * @var string $csrfToken
 * @var string $accountsUrl
 * @var string $createUrl
 * @var string $passwordUrl
 * @var string $deleteUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 * @var array{username: string, display_name: string} $createValues
 */
$accountCount = count($administrators);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Administrator accounts</title>
</head>
<body class="admin">
<?php $current = 'admin'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Access</span>
            <h1>Administrators</h1>
            <p class="lede">Every administrator can publish, delete, and change site settings. Add an account only for someone who needs that full access.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip chip--plain"><?= $accountCount === 1 ? '1 account' : $accountCount . ' accounts' ?></span>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <div class="panel-head">
        <h2>People with access</h2>
        <span class="muted">Passwords are hashed and cannot be read back.</span>
    </div>
    <div class="table-wrap">
        <table class="table--stack">
            <caption class="table-caption">Signed-in account and other administrators. Removing an account ends its sessions immediately.</caption>
            <thead>
                <tr>
                    <th scope="col">Account</th>
                    <th scope="col">Username</th>
                    <th scope="col">Added</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($administrators as $administrator): ?>
                    <?php $isCurrent = $administrator['id'] === $currentAdminId; ?>
                    <tr>
                        <td data-label="Account">
                            <span class="table-title"><?= Html::escape($administrator['display_name']) ?></span>
                            <?php if ($isCurrent): ?>
                                <span class="chip chip--published">You</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Username"><code><?= Html::escape($administrator['username']) ?></code></td>
                        <td data-label="Added" class="num"><time datetime="<?= Html::escape($administrator['created_at']) ?>"><?= Html::escape(substr($administrator['created_at'], 0, 10)) ?></time></td>
                        <td data-label="Actions" class="table-actions">
                            <?php if ($isCurrent): ?>
                                <span class="muted">Current account</span>
                            <?php else: ?>
                                <details class="menu-details menu-details--danger">
                                    <summary>Remove</summary>
                                    <form method="post" action="<?= Html::escape($deleteUrl) ?>">
                                        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                        <input type="hidden" name="admin_id" value="<?= Html::escape($administrator['id']) ?>">
                                        <p class="hint">Removes <strong><?= Html::escape($administrator['username']) ?></strong> and signs out its sessions. Their published pages stay attributed to the display name.</p>
                                        <button type="submit" class="button button--danger">Remove <?= Html::escape($administrator['username']) ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="split">
        <section class="panel" aria-labelledby="add-admin">
            <div class="panel-head">
                <h2 id="add-admin">Add an administrator</h2>
            </div>
            <form method="post" action="<?= Html::escape($createUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <div class="grid-2">
                    <div class="field">
                        <label for="new-username">Username</label>
                        <input id="new-username" name="username" type="text" value="<?= Html::escape($createValues['username']) ?>" autocomplete="off" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]+">
                        <p class="hint">Letters, numbers, dot, dash, or underscore. Used to sign in.</p>
                    </div>
                    <div class="field">
                        <label for="new-display-name">Display name</label>
                        <input id="new-display-name" name="display_name" type="text" value="<?= Html::escape($createValues['display_name']) ?>" required maxlength="200">
                        <p class="hint">Shown as the author on published pages.</p>
                    </div>
                    <div class="field">
                        <label for="new-password">Password</label>
                        <input id="new-password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="4096">
                        <p class="hint">At least 12 characters. Share it with them directly, never by page content.</p>
                    </div>
                    <div class="field">
                        <label for="new-password-confirmation">Confirm password</label>
                        <input id="new-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="4096">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Add administrator</button>
                </div>
            </form>
        </section>

        <section class="panel" aria-labelledby="own-password">
            <div class="panel-head">
                <h2 id="own-password">Change your password</h2>
            </div>
            <form method="post" action="<?= Html::escape($passwordUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                <input type="hidden" name="admin_id" value="<?= Html::escape($currentAdminId) ?>">
                <div class="field">
                    <label for="current-password">Current password</label>
                    <input id="current-password" name="current_password" type="password" autocomplete="current-password" required maxlength="4096">
                </div>
                <div class="field">
                    <label for="own-new-password">New password</label>
                    <input id="own-new-password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="4096">
                </div>
                <div class="field">
                    <label for="own-password-confirmation">Confirm new password</label>
                    <input id="own-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="4096">
                </div>
                <div class="form-actions">
                    <button type="submit" class="button">Change password</button>
                </div>
                <p class="panel-foot">Changing your password signs out every other session for this account. There is no web recovery; a forgotten password is reset from the command line by the site operator.</p>
            </form>
        </section>
    </div>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
