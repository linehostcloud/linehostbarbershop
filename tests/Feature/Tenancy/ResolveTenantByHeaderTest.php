<?php

namespace Tests\Feature\Tenancy;

use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class ResolveTenantByHeaderTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_resolves_the_tenant_from_the_slug_header_on_a_central_domain(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-filial',
            domain: 'barbearia-filial.test',
        );

        $response = $this
            ->withHeader(config('tenancy.identification.tenant_slug_header'), $tenant->slug)
            ->getJson('http://localhost/api/v1/tenant/context');

        $response
            ->assertOk()
            ->assertJsonPath('data.tenant_slug', $tenant->slug)
            ->assertJsonPath('data.resolved_host', 'localhost');
    }

    public function test_it_returns_not_found_when_a_central_domain_request_has_no_tenant_context(): void
    {
        $response = $this->getJson('http://localhost/api/v1/tenant/context');

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Nenhum tenant foi encontrado para o host "localhost".');
    }
}
