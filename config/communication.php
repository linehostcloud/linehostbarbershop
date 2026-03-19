<?php

return [
    'whatsapp' => [
        'default_testing_provider' => env('WHATSAPP_DEFAULT_PROVIDER', 'fake'),
        'default_timeout_seconds' => (int) env('WHATSAPP_DEFAULT_TIMEOUT_SECONDS', 10),
        'allow_private_network_targets' => (bool) env('WHATSAPP_ALLOW_PRIVATE_NETWORK_TARGETS', false),
        'deduplication' => [
            'window_minutes' => (int) env('WHATSAPP_DEDUPLICATION_WINDOW_MINUTES', 15),
        ],
        'health' => [
            'window_minutes' => (int) env('WHATSAPP_PROVIDER_HEALTH_WINDOW_MINUTES', 30),
            'unstable_failure_rate' => (float) env('WHATSAPP_PROVIDER_HEALTH_UNSTABLE_FAILURE_RATE', 50),
            'unstable_retry_threshold' => (int) env('WHATSAPP_PROVIDER_HEALTH_UNSTABLE_RETRY_THRESHOLD', 3),
            'unstable_signal_threshold' => (int) env('WHATSAPP_PROVIDER_HEALTH_UNSTABLE_SIGNAL_THRESHOLD', 3),
        ],
        'testing_providers' => [
            'fake',
            'fake-transient-failure',
        ],
        'supported_providers' => [
            'whatsapp_cloud',
            'evolution_api',
            'gowa',
        ],
    ],
];
