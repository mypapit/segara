<?php

declare(strict_types=1);

// Copy to settings/config.php and replace the canonical URL for the deployment.
return [
    'base_url' => 'http://localhost:8080',
    'base_path' => '',
    'environment' => 'development',
    'session' => [
        'idle_timeout' => 1800,
        'absolute_timeout' => 28800,
    ],
    'cache' => [
        'enabled' => false,
    ],
    'captcha' => [
        'provider' => null,
        'site_key' => null,
        'secret_key' => null,
    ],
    'auth' => [
        'basic_realm' => 'Segara Administration',
    ],
];
