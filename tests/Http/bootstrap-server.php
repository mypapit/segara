#!/usr/bin/env php
<?php

declare(strict_types=1);

// Prepare an isolated fixture store for the HTTP acceptance suite.
// Usage: php tests/Http/bootstrap-server.php <root> <baseUrl> <adminPassword>

use Segara\Auth\AdminRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not Found\n";
    exit(1);
}

$root = $argv[1] ?? null;
$baseUrl = $argv[2] ?? null;
$password = $argv[3] ?? null;
if (!is_string($root) || !is_string($baseUrl) || !is_string($password)) {
    fwrite(STDERR, "ERROR: root, baseUrl, and adminPassword are required.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';

foreach (['store', 'store/sessions', 'store/logs', 'store/backups', 'uploads', 'settings'] as $sub) {
    if (!is_dir($root . '/' . $sub)) {
        @mkdir($root . '/' . $sub, 0777, true);
    }
}

$config = '<?php' . "\n\n"
    . 'declare(strict_types=1);' . "\n\n"
    . 'return ' . var_export([
        'base_url' => $baseUrl,
        'base_path' => '',
        'environment' => 'test',
        'session' => ['idle_timeout' => 1800, 'absolute_timeout' => 28800],
        'cache' => ['enabled' => false],
        'captcha' => ['provider' => null, 'site_key' => null, 'secret_key' => null],
        'auth' => ['basic_realm' => 'Segara Administration'],
    ], true) . ";\n";
file_put_contents($root . '/settings/config.php', $config);

$pdo = Database::connect($root . '/store/segara.sqlite');
(new Migrator($pdo, $projectRoot . '/migrations'))->migrate();
(new AdminRepository($pdo))->create('admin', 'Site Administrator', $password);

echo "READY\n";
exit(0);
