<?php

return [
    'whatsapp' => [
        'default_testing_provider' => env('WHATSAPP_DEFAULT_PROVIDER', 'fake'),
        'default_timeout_seconds' => (int) env('WHATSAPP_DEFAULT_TIMEOUT_SECONDS', 10),
        'allow_private_network_targets' => (bool) env('WHATSAPP_ALLOW_PRIVATE_NETWORK_TARGETS', false),
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
