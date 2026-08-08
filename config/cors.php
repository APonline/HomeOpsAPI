<?php

return [

    'paths' => [
        'api/*',
    ],

    'allowed_methods' => [
        '*',
    ],

    'allowed_origins' => [
        'https://runhomeops.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*',
    ],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];