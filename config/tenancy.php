<?php

$appUrlHost = parse_url((string) env('APP_URL', 'http://sistema-barbearia.localhost'), PHP_URL_HOST);
$appHost = is_string($appUrlHost) && $appUrlHost !== ''
    ? mb_strtolower(trim($appUrlHost))
    : 'sistema-barbearia.localhost';

$centralDomains = array_values(array_unique(array_filter(array_map(
    static fn (string $domain): string => mb_strtolower(trim($domain)),
    explode(',', (string) env('CENTRAL_DOMAINS', $appHost.',localhost,127.0.0.1'))
))));

if (! in_array($appHost, $centralDomains, true)) {
    $centralDomains[] = $appHost;
}

return [
    'landlord_connection' => 'landlord',
    'tenant_connection' => 'tenant',

    'central_domains' => $centralDomains,

    'identification' => [
        'tenant_slug_header' => env('TENANT_SLUG_HEADER', 'X-Tenant-Slug'),
        'local_browser_domain_suffix' => env('TENANT_LOCAL_BROWSER_DOMAIN_SUFFIX', $appHost),
    ],

    'provisioning' => [
        'database_prefix' => env('TENANT_DATABASE_PREFIX', 'tenant_'),
        'database_charset' => env('TENANT_DATABASE_CHARSET', 'utf8mb4'),
        'database_collation' => env('TENANT_DATABASE_COLLATION', 'utf8mb4_unicode_ci'),
        'default_domain_suffix' => env('TENANT_DEFAULT_DOMAIN_SUFFIX', $appHost),
    ],
];
