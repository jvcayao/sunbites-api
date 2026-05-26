<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_merge(
        [
            'http://localhost:3000',
            'http://localhost:3001',
            'https://pos.sunbites.com.ph',
            'https://portal.sunbites.com.ph',
            'https://pos-staging.sunbites.com.ph',
            'https://portal-staging.sunbites.com.ph',
        ],
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))
    )),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Branch-Id'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
