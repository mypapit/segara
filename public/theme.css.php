<?php

declare(strict_types=1);

use Segara\View\Themes;

require dirname(__DIR__) . '/vendor/autoload.php';

$theme = $_GET['theme'] ?? '';
if (!is_string($theme) || !in_array($theme, Themes::ALL, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found\n";
    exit;
}

$file = dirname(__DIR__) . '/themes/' . $theme . '/assets/theme.css';
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found\n";
    exit;
}

$size = filesize($file);
header('Content-Type: text/css; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
if ($size !== false) {
    header('Content-Length: ' . (string) $size);
}
readfile($file);
