<?php

declare(strict_types=1);

use Segara\Config;
use Segara\Support\Html;

/** @var string $csrfToken */
/** @var string $formAction */
/** @var Config $config */
/** @var string|null $error */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d1c23">
    <link rel="stylesheet" href="<?= Html::escape($config->basePath . '/assets/admin.css') ?>">
    <title>Administrator login</title>
</head>
<body class="login">
<main class="login-wrap">
    <div class="login-card">
        <p class="login-brand">
            <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.8 15.2c2.3 0 2.3-1.9 4.6-1.9s2.3 1.9 4.6 1.9 2.3-1.9 4.6-1.9 2.3 1.9 4.6 1.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <path d="M2.8 9.4c2.3 0 2.3-1.9 4.6-1.9s2.3 1.9 4.6 1.9 2.3-1.9 4.6-1.9 2.3 1.9 4.6 1.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" opacity="0.55"/>
            </svg>
            <span>Segara</span>
        </p>
        <h1>Sign in</h1>
        <p class="lede">Administration for this site. Visitors do not need an account.</p>
        <?php if ($error !== null): ?>
            <p class="alert" role="alert"><?= Html::escape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= Html::escape($formAction) ?>">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="64">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required minlength="12" maxlength="4096">
            </div>
            <?php if ($config->captchaProvider === 'recaptcha'): ?>
                <div class="g-recaptcha" data-sitekey="<?= Html::escape((string) $config->captchaSiteKey) ?>"></div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <?php elseif ($config->captchaProvider === 'turnstile'): ?>
                <div class="cf-turnstile" data-sitekey="<?= Html::escape((string) $config->captchaSiteKey) ?>"></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <?php endif; ?>
            <button type="submit" class="button button-primary button-block">Log in</button>
        </form>
        <p class="login-foot">Sessions end after a period of inactivity. A forgotten password is reset from the command line by the site operator — there is no email recovery.</p>
    </div>
    <p class="login-meta">Segara · local administration</p>
</main>
</body>
</html>
