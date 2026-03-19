<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantAuthenticationApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_logs_in_on_a_tenant_and_returns_the_authenticated_context(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-auth',
            domain: 'barbearia-auth.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-auth.test',
            password: 'password123',
        );

        $loginResponse = $this->postJson($this->tenantUrl($tenant, '/auth/login'), [
            'email' => $user->email,
            'password' => 'password123',
            'device_name' => 'painel-web',
        ]);

        $token = $loginResponse
            ->assertStatus(201)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.membership.role', 'manager')
            ->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.membership.role', 'manager');
    }

    public function test_it_requires_authentication_for_protected_tenant_routes(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-protegida',
            domain: 'barbearia-protegida.test',
        );

        $this->getJson($this->tenantUrl($tenant, '/clients'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token de acesso ausente ou inválido.');
    }

    public function test_it_rejects_a_token_issued_for_another_tenant(): void
    {
        $primaryTenant = $this->provisionTenant(
            slug: 'barbearia-token-a',
            domain: 'barbearia-token-a.test',
        );
        $secondaryTenant = $this->provisionTenant(
            slug: 'barbearia-token-b',
            domain: 'barbearia-token-b.test',
        );
        $user = $this->createTenantUser(
            tenant: $primaryTenant,
            role: 'owner',
            email: 'owner@barbearia-token-a.test',
        );
        $token = $this->issueTenantAccessToken($primaryTenant, $user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($this->tenantUrl($secondaryTenant, '/clients'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token de acesso ausente ou inválido.');
    }

    public function test_it_forbids_finance_writes_for_roles_without_that_ability(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-recepcao',
            domain: 'barbearia-recepcao.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            email: 'recepcao@barbearia-recepcao.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist', user: $user))
            ->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
                'label' => 'Caixa principal',
                'opening_balance_cents' => 1000,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.');
    }

    public function test_it_revokes_the_current_token_on_logout(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-logout',
            domain: 'barbearia-logout.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-logout.test',
            password: 'password123',
        );

        $token = $this->postJson($this->tenantUrl($tenant, '/auth/login'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(201)->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->tenantUrl($tenant, '/auth/logout'))
            ->assertNoContent();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token de acesso ausente ou inválido.');
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
