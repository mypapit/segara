<?php

declare(strict_types=1);

use Segara\Support\Html;

/** @var array<string, mixed> $metadata */
$author = $metadata['author'];
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Html::escape($metadata['title']) ?></title>
<?php if ($metadata['description'] !== null): ?>
<meta name="description" content="<?= Html::escape($metadata['description']) ?>">
<?php endif; ?>
<?php if ($metadata['robots'] !== null): ?>
<meta name="robots" content="<?= Html::escape($metadata['robots']) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= Html::escape($metadata['canonical']) ?>">
<meta property="og:title" content="<?= Html::escape($metadata['title']) ?>">
<meta property="og:type" content="<?= Html::escape($metadata['opengraph_type']) ?>">
<meta property="og:url" content="<?= Html::escape($metadata['canonical']) ?>">
<meta property="og:site_name" content="<?= Html::escape($metadata['site_name']) ?>">
<meta property="og:locale" content="<?= Html::escape($metadata['language']) ?>">
<?php if ($metadata['description'] !== null): ?>
<meta property="og:description" content="<?= Html::escape($metadata['description']) ?>">
<?php endif; ?>
<?php if ($metadata['image'] !== null): ?>
<meta property="og:image" content="<?= Html::escape($metadata['image']) ?>">
<?php endif; ?>
<?php if ($metadata['published_at'] !== null && $metadata['opengraph_type'] === 'article'): ?>
<meta property="article:published_time" content="<?= Html::escape($metadata['published_at']) ?>">
<?php endif; ?>
<?php if ($metadata['modified_at'] !== null && $metadata['opengraph_type'] === 'article'): ?>
<meta property="article:modified_time" content="<?= Html::escape($metadata['modified_at']) ?>">
<?php endif; ?>
<meta name="DC.title" content="<?= Html::escape($metadata['title']) ?>">
<?php if ($metadata['description'] !== null): ?>
<meta name="DC.description" content="<?= Html::escape($metadata['description']) ?>">
<?php endif; ?>
<meta name="DC.language" content="<?= Html::escape($metadata['language']) ?>">
<meta name="DC.identifier" content="<?= Html::escape($metadata['canonical']) ?>">
<meta name="DC.type" content="Text">
<?php if (is_array($author)): ?>
<meta name="DC.creator" content="<?= Html::escape($author['name']) ?>">
<?php endif; ?>
<?php if ($metadata['published_at'] !== null): ?>
<meta name="DC.date" content="<?= Html::escape($metadata['published_at']) ?>">
<?php endif; ?>
<?php if ($metadata['modified_at'] !== null): ?>
<meta name="DC.modified" content="<?= Html::escape($metadata['modified_at']) ?>">
<?php endif; ?>
<script type="application/ld+json"><?= Html::json($metadata['json_ld']) ?></script>
