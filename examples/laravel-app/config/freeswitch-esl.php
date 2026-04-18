<?php

return [
    'default_driver' => env('FREESWITCH_ESL_DRIVER', 'freeswitch'),

    'observability' => [
        'metrics' => [
            // Use "event" if you want the example provider to mirror metrics into the app log.
            'driver' => env('FREESWITCH_ESL_METRICS_DRIVER', 'log'),
            'log_level' => env('FREESWITCH_ESL_METRICS_LOG_LEVEL', 'info'),
        ],
    ],

    'http' => [
        'health' => [
            'enabled' => env('FREESWITCH_ESL_HTTP_HEALTH_ENABLED', true),
            'prefix' => env('FREESWITCH_ESL_HTTP_HEALTH_PREFIX', 'freeswitch-esl/health'),
            'middleware' => [],
        ],
    ],

    'replay' => [
        'enabled' => env('FREESWITCH_ESL_REPLAY_ENABLED', false),
        'store_driver' => env('FREESWITCH_ESL_REPLAY_STORE', 'database'),
    ],
];
