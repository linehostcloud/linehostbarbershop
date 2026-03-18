<?php

return [
    'landlord_connection' => 'landlord',
    'tenant_connection' => 'tenant',

    'central_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => mb_strtolower(trim($domain)),
        explode(',', (string) env('CENTRAL_DOMAINS', 'sistemabarbearia.local,localhost,127.0.0.1'))
    ))),

    'identification' => [
        'tenant_slug_header' => env('TENANT_SLUG_HEADER', 'X-Tenant-Slug'),
    ],

    'provisioning' => [
        'database_prefix' => env('TENANT_DATABASE_PREFIX', 'tenant_'),
        'database_charset' => env('TENANT_DATABASE_CHARSET', 'utf8mb4'),
        'database_collation' => env('TENANT_DATABASE_COLLATION', 'utf8mb4_unicode_ci'),
        'default_domain_suffix' => env('TENANT_DEFAULT_DOMAIN_SUFFIX', 'sistemabarbearia.local'),
    ],
];
