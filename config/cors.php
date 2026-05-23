<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'https://pos.sunbites.com.ph',
        'https://portal.sunbites.com.ph',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Branch-Id'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
