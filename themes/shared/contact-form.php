<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string $csrfToken @var string|null $saved @var string|null $error */
?>
<section class="contact-form" aria-labelledby="contact-heading">
    <h2 id="contact-heading">Contact</h2>
    <p class="contact-form__intro">All fields are required.</p>
    <?php if ($saved !== null): ?>
        <p class="contact-form__saved" role="status"><?= Html::escape($saved) ?></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <p class="contact-form__error" role="alert"><?= Html::escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="" class="contact-form__form">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrfToken) ?>">
        <p class="contact-form__honeypot" aria-hidden="true">
            <label>Leave this field empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </p>
        <div class="contact-form__grid">
            <p class="contact-form__field contact-form__field--half">
                <label for="contact-name">Name</label>
                <input id="contact-name" name="name" type="text" required maxlength="200" autocomplete="name">
            </p>
            <p class="contact-form__field contact-form__field--half">
                <label for="contact-email">Email</label>
                <input id="contact-email" name="email" type="email" required maxlength="254" autocomplete="email">
            </p>
            <p class="contact-form__field contact-form__field--full">
                <label for="contact-subject">Subject</label>
                <input id="contact-subject" name="subject" type="text" required maxlength="200">
            </p>
            <p class="contact-form__field contact-form__field--full">
                <label for="contact-message">Message</label>
                <textarea id="contact-message" name="message" required maxlength="10000" rows="8"></textarea>
            </p>
            <p class="contact-form__actions">
                <button type="submit">Send message</button>
            </p>
        </div>
    </form>
</section>
