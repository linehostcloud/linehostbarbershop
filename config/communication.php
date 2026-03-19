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
        'product_flows' => [
            'appointment_confirmation' => [
                'message' => [
                    'type' => 'text',
                    'body_text' => 'Oi {{client.first_name}}, seu agendamento em {{appointment.starts_at_local}} está confirmado? Se precisar ajustar o horário, responda esta mensagem.',
                    'payload_json' => [],
                ],
            ],
            'client_reactivation_snooze' => [
                'days' => (int) env('WHATSAPP_PRODUCT_CLIENT_REACTIVATION_SNOOZE_DAYS', 7),
            ],
        ],
        'agent' => [
            'window_minutes' => (int) env('WHATSAPP_AGENT_WINDOW_MINUTES', 120),
            'provider_signal_alert_threshold' => (int) env('WHATSAPP_AGENT_PROVIDER_SIGNAL_ALERT_THRESHOLD', 2),
            'duplicate_risk_alert_threshold' => (int) env('WHATSAPP_AGENT_DUPLICATE_RISK_ALERT_THRESHOLD', 2),
            'delivery_instability_issue_threshold' => (int) env('WHATSAPP_AGENT_DELIVERY_INSTABILITY_ISSUE_THRESHOLD', 4),
            'delivery_instability_min_attempts' => (int) env('WHATSAPP_AGENT_DELIVERY_INSTABILITY_MIN_ATTEMPTS', 5),
            'delivery_instability_failure_rate' => (float) env('WHATSAPP_AGENT_DELIVERY_INSTABILITY_FAILURE_RATE', 25),
            'reminder_opportunity_min_candidates' => (int) env('WHATSAPP_AGENT_REMINDER_OPPORTUNITY_MIN_CANDIDATES', 2),
            'reactivation_opportunity_min_candidates' => (int) env('WHATSAPP_AGENT_REACTIVATION_OPPORTUNITY_MIN_CANDIDATES', 3),
            'automation_stale_days' => (int) env('WHATSAPP_AGENT_AUTOMATION_STALE_DAYS', 7),
            'ignored_reopen_hours' => (int) env('WHATSAPP_AGENT_IGNORED_REOPEN_HOURS', 24),
        ],
        'execution_locks' => [
            'automations_seconds' => (int) env('WHATSAPP_AUTOMATIONS_LOCK_SECONDS', 300),
            'agent_seconds' => (int) env('WHATSAPP_AGENT_LOCK_SECONDS', 300),
            'outbox_seconds' => (int) env('WHATSAPP_OUTBOX_LOCK_SECONDS', 120),
            'reclaim_seconds' => (int) env('WHATSAPP_RECLAIM_LOCK_SECONDS', 120),
            'housekeeping_seconds' => (int) env('WHATSAPP_HOUSEKEEPING_LOCK_SECONDS', 600),
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
