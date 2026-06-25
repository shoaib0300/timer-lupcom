<?php

declare(strict_types=1);

return [
    'name' => 'Timer',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    'url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
    'views_path' => dirname(__DIR__) . '/resources/views',
    'timezone' => 'UTC',
];
