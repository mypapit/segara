<?php
declare(strict_types=1);
use Segara\Support\Html;

/**
 * @var list<array{id: string, name: string, email: string, subject: string, body: string, is_read: int, created_at: string, updated_at: string}> $messages
 * @var string $csrfToken
 * @var string $markReadUrl
 * @var string $deleteUrl
 * @var string $accountsUrl
 * @var string $logoutUrl
 * @var string $basePath
 * @var string|null $error
 */
$unread = 0;
foreach ($messages as $message) {
    if ($message['is_read'] !== 1) {
        $unread++;
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($basePath . '/assets/admin.css') ?>">
    <title>Messages</title>
</head>
<body class="admin">
<?php $current = 'settings'; require __DIR__ . '/partial-nav.php'; ?>
<main class="admin-main" id="main">
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow">Contact</span>
            <h1>Messages</h1>
            <p class="lede">Submissions from contact forms, stored locally. The site does not send email, so this page is the inbox.</p>
        </div>
        <div class="page-head__actions">
            <span class="chip <?= $unread > 0 ? 'chip--published' : 'chip--plain' ?>"><?= $unread ?> unread</span>
            <a class="button" href="<?= Html::escape($basePath . '/admin.php?action=settings') ?>">Back to settings</a>
        </div>
    </div>
    <?php if ($error !== null): ?>
        <p role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <?php if (count($messages) === 0): ?>
        <div class="empty-state">
            <strong>No messages yet.</strong>
            Messages arrive when a visitor submits a contact form on a page that has one enabled.
        </div>
    <?php else: ?>
        <ul class="message-list">
            <?php foreach ($messages as $message): ?>
                <?php $isUnread = $message['is_read'] !== 1; ?>
                <li class="message <?= $isUnread ? 'message--unread' : 'message--read' ?>">
                    <h2><?= Html::escape($message['subject']) ?></h2>
                    <p class="message-meta">
                        <span class="chip <?= $isUnread ? 'chip--published' : 'chip--plain' ?>"><?= $isUnread ? 'Unread' : 'Read' ?></span>
                        <?= Html::escape($message['name']) ?> &lt;<?= Html::escape($message['email']) ?>&gt; ·
                        <time datetime="<?= Html::escape($message['created_at']) ?>"><?= Html::escape($message['created_at']) ?></time>
                    </p>
                    <p class="message-body"><?= Html::escape($message['body']) ?></p>
                    <div class="actions">
                        <?php if ($isUnread): ?>
                            <form method="post" action="<?= Html::escape($markReadUrl) ?>" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                                <input type="hidden" name="message_id" value="<?= Html::escape($message['id']) ?>">
                                <button type="submit" class="button button--sm">Mark read</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= Html::escape($deleteUrl) ?>" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
                            <input type="hidden" name="message_id" value="<?= Html::escape($message['id']) ?>">
                            <button type="submit" class="button button--sm button--danger">Delete</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
<footer class="admin-footer">Segara · administration</footer>
</body>
</html>
