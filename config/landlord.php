<?php

$adminEmails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', (string) env('LANDLORD_PANEL_ADMIN_EMAILS', ''))
))));

return [
    'admin_emails' => $adminEmails,

    'panel' => [
        'path_prefix' => 'painel/saas',
    ],

    'tenants' => [
        'list_per_page' => 15,
        'schema_required_tables' => [
            'clients',
            'appointments',
            'messages',
        ],
        'detail_snapshot' => [
            'stale_after_seconds' => (int) env('LANDLORD_TENANT_DETAIL_SNAPSHOT_STALE_AFTER_SECONDS', 900),
            'lock_seconds' => (int) env('LANDLORD_TENANT_DETAIL_SNAPSHOT_LOCK_SECONDS', 300),
            'scheduled_refresh_enabled' => filter_var(env('LANDLORD_TENANT_DETAIL_SNAPSHOT_SCHEDULED_REFRESH_ENABLED', true), FILTER_VALIDATE_BOOL),
        ],
        'defaults' => [
            'plan_code' => 'starter',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
        ],
    ],
];
