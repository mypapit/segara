<?php
declare(strict_types=1);
use Segara\Support\Html;

/** @var string|null $announcement */
if ($announcement !== null && $announcement !== ''): ?>
    <aside class="announcement" role="note"><?= Html::escape($announcement) ?></aside>
<?php endif;
