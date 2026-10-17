<?php

declare(strict_types=1);

return [
    'paths' => ['api/*', 'health', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ORIGIN', env('FRONTEND_URL', 'http://localhost:5173')))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
