<?php

return [
    'outbox' => [
        'default_max_attempts' => (int) env('OUTBOX_DEFAULT_MAX_ATTEMPTS', 5),
        'default_retry_backoff_seconds' => (int) env('OUTBOX_DEFAULT_RETRY_BACKOFF_SECONDS', 60),
        'default_batch_size' => (int) env('OUTBOX_DEFAULT_BATCH_SIZE', 50),
        'reclaim' => [
            'enabled' => filter_var(env('OUTBOX_RECLAIM_ENABLED', true), FILTER_VALIDATE_BOOL),
            'auto_run_on_process' => filter_var(env('OUTBOX_RECLAIM_AUTO_RUN_ON_PROCESS', true), FILTER_VALIDATE_BOOL),
            'stale_after_seconds' => (int) env('OUTBOX_RECLAIM_STALE_AFTER_SECONDS', 300),
            'max_attempts' => (int) env('OUTBOX_RECLAIM_MAX_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('OUTBOX_RECLAIM_BACKOFF_SECONDS', 30),
        ],
    ],
    'whatsapp_operations' => [
        'default_window' => env('WHATSAPP_OPERATIONS_DEFAULT_WINDOW', '24h'),
        'allowed_windows' => ['24h', '7d', '30d'],
        'default_per_page' => (int) env('WHATSAPP_OPERATIONS_DEFAULT_PER_PAGE', 20),
        'max_per_page' => (int) env('WHATSAPP_OPERATIONS_MAX_PER_PAGE', 100),
        'default_feed_limit' => (int) env('WHATSAPP_OPERATIONS_DEFAULT_FEED_LIMIT', 20),
        'default_boundary_latest_limit' => (int) env('WHATSAPP_OPERATIONS_DEFAULT_BOUNDARY_LATEST_LIMIT', 10),
        'default_top_error_codes_limit' => (int) env('WHATSAPP_OPERATIONS_DEFAULT_TOP_ERROR_CODES_LIMIT', 5),
    ],
    'whatsapp_housekeeping' => [
        'default_batch_size' => (int) env('WHATSAPP_HOUSEKEEPING_DEFAULT_BATCH_SIZE', 200),
        'automation_runs_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_AUTOMATION_RUNS_RETAIN_DAYS', 30),
        'agent_runs_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_AGENT_RUNS_RETAIN_DAYS', 30),
        'agent_insights_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_AGENT_INSIGHTS_RETAIN_DAYS', 45),
        'event_logs_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_EVENT_LOGS_RETAIN_DAYS', 60),
        'outbox_events_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_OUTBOX_EVENTS_RETAIN_DAYS', 30),
        'integration_attempts_retain_days' => (int) env('WHATSAPP_HOUSEKEEPING_INTEGRATION_ATTEMPTS_RETAIN_DAYS', 45),
    ],
    'landlord_tenants_index' => [
        'performance_logging_enabled' => filter_var(env('LANDLORD_TENANTS_INDEX_PERFORMANCE_LOGGING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'failure_detail_limit' => (int) env('LANDLORD_TENANTS_INDEX_PERFORMANCE_FAILURE_DETAIL_LIMIT', 5),
    ],
    'landlord_tenants_detail' => [
        'performance_logging_enabled' => filter_var(env('LANDLORD_TENANTS_DETAIL_PERFORMANCE_LOGGING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'failure_detail_limit' => (int) env('LANDLORD_TENANTS_DETAIL_PERFORMANCE_FAILURE_DETAIL_LIMIT', 5),
    ],
    'landlord_tenants_detail_snapshot' => [
        'refresh_logging_enabled' => filter_var(env('LANDLORD_TENANT_DETAIL_SNAPSHOT_REFRESH_LOGGING_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
];
