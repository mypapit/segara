<?php

declare(strict_types=1);

use Segara\Media\MediaRepository;
use Segara\Storage\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
$data = getenv('SEGARA_DATA') ?: dirname(__DIR__);

try {
    $id = $_GET['id'] ?? null;
    if (!is_string($id) || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1) {
        http_response_code(404);
        exit;
    }

    $pdo = Database::connect($data . '/store/segara.sqlite');
    $media = (new MediaRepository($pdo))->getById($id);
    if ($media === null) {
        http_response_code(404);
        exit;
    }

    $storedPath = $media['stored_path'];
    if (preg_match('/\A[0-9a-f]{32}\.(jpg|png|webp)\z/D', $storedPath) !== 1) {
        http_response_code(404);
        exit;
    }

    $uploadRoot = $data . '/uploads';
    $realRoot = realpath($uploadRoot);
    $realFile = realpath($uploadRoot . '/' . $storedPath);
    if ($realRoot === false || $realFile === false || !is_file($realFile)
        || !str_starts_with($realFile, $realRoot . '/')
    ) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $media['mime']);
    header('Content-Length: ' . (string) filesize($realFile));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($realFile);
} catch (Throwable) {
    http_response_code(404);
}
