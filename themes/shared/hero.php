<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string $heroTitle @var string $heroLead */
if ($heroTitle !== ''): ?>
    <section class="hero">
        <h1><?= Html::escape($heroTitle) ?></h1>
        <?php if ($heroLead !== ''): ?><p class="hero__lead"><?= Html::escape($heroLead) ?></p><?php endif; ?>
    </section>
<?php endif;
