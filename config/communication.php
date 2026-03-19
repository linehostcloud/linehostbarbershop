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
        'automations' => [
            'default_processing_limit' => (int) env('WHATSAPP_AUTOMATIONS_DEFAULT_PROCESSING_LIMIT', 100),
            'selection_tolerance_minutes' => (int) env('WHATSAPP_AUTOMATIONS_SELECTION_TOLERANCE_MINUTES', 10),
            'appointment_reminder' => [
                'default_status' => env('WHATSAPP_AUTOMATION_APPOINTMENT_REMINDER_STATUS', 'inactive'),
                'lead_time_minutes' => (int) env('WHATSAPP_AUTOMATION_APPOINTMENT_REMINDER_LEAD_TIME_MINUTES', 1440),
                'cooldown_hours' => (int) env('WHATSAPP_AUTOMATION_APPOINTMENT_REMINDER_COOLDOWN_HOURS', 24),
                'message' => [
                    'type' => 'text',
                    'body_text' => 'Oi {{client.first_name}}, lembrando do seu agendamento em {{appointment.starts_at_local}}.',
                    'payload_json' => [],
                ],
            ],
            'inactive_client_reactivation' => [
                'default_status' => env('WHATSAPP_AUTOMATION_REACTIVATION_STATUS', 'inactive'),
                'inactivity_days' => (int) env('WHATSAPP_AUTOMATION_REACTIVATION_INACTIVITY_DAYS', 45),
                'cooldown_hours' => (int) env('WHATSAPP_AUTOMATION_REACTIVATION_COOLDOWN_HOURS', 720),
                'minimum_completed_visits' => (int) env('WHATSAPP_AUTOMATION_REACTIVATION_MINIMUM_COMPLETED_VISITS', 1),
                'message' => [
                    'type' => 'text',
                    'body_text' => 'Oi {{client.first_name}}, sentimos sua falta por aqui. Se quiser, podemos te ajudar a agendar seu proximo horario.',
                    'payload_json' => [],
                ],
            ],
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
