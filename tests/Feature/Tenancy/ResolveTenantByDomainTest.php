<?php

namespace Tests\Feature\Tenancy;

use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class ResolveTenantByDomainTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_resolves_the_tenant_from_the_request_host(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-matriz',
            domain: 'barbearia-matriz.test',
        );

        $response = $this->getJson('http://barbearia-matriz.test/api/v1/tenant/context');

        $response
            ->assertOk()
            ->assertJsonPath('data.tenant_slug', $tenant->slug)
            ->assertJsonPath('data.resolved_host', 'barbearia-matriz.test')
            ->assertJsonPath('data.tenant_connection', 'tenant')
            ->assertJsonPath(
                'data.tenant_database',
                database_path('tenant_barbearia_matriz.sqlite'),
            );
    }
}
