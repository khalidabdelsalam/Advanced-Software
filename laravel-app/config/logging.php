<?php

use Monolog\Formatter\JsonFormatter;

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'aiops' => [
            'driver' => 'single',
            'path' => storage_path('logs/aiops.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'formatter' => JsonFormatter::class,
        ],
    ],
];
