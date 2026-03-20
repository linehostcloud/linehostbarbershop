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
        'defaults' => [
            'plan_code' => 'starter',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
        ],
    ],
];
