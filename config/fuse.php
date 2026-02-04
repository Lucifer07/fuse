<?php

return [
    'enabled' => env('FUSE_ENABLED', true),

    'default_threshold' => env('FUSE_DEFAULT_THRESHOLD', 50),
    'default_timeout' => env('FUSE_DEFAULT_TIMEOUT', 60),
    'default_min_requests' => env('FUSE_DEFAULT_MIN_REQUESTS', 10),

    'services' => [
        'stripe' => [
            'threshold' => env('FUSE_STRIPE_THRESHOLD', 40),
            'peak_hours_threshold' => env('FUSE_STRIPE_PEAK_THRESHOLD', 60),
            'peak_hours_start' => env('FUSE_STRIPE_PEAK_START', 9),
            'peak_hours_end' => env('FUSE_STRIPE_PEAK_END', 17),
            'timeout' => env('FUSE_STRIPE_TIMEOUT', 30),
            'min_requests' => env('FUSE_STRIPE_MIN_REQUESTS', 5),
        ],

        'mailgun' => [
            'threshold' => env('FUSE_MAILGUN_THRESHOLD', 50),
            'peak_hours_threshold' => env('FUSE_MAILGUN_PEAK_THRESHOLD', 70),
            'peak_hours_start' => env('FUSE_MAILGUN_PEAK_START', 9),
            'peak_hours_end' => env('FUSE_MAILGUN_PEAK_END', 17),
            'timeout' => env('FUSE_MAILGUN_TIMEOUT', 120),
            'min_requests' => env('FUSE_MAILGUN_MIN_REQUESTS', 10),
        ],

        'api' => [
            'threshold' => env('FUSE_API_THRESHOLD', 40),
            'peak_hours_threshold' => env('FUSE_API_PEAK_THRESHOLD', 60),
            'peak_hours_start' => env('FUSE_API_PEAK_START', 9),
            'peak_hours_end' => env('FUSE_API_PEAK_END', 17),
            'timeout' => env('FUSE_API_TIMEOUT', 60),
            'min_requests' => env('FUSE_API_MIN_REQUESTS', 10),
        ],
    ],
];
