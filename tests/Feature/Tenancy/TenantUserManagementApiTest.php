<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantUserManagementApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_owner_can_invite_a_user_and_the_user_can_accept_the_invitation(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-equipe',
            domain: 'barbearia-equipe.test',
        );

        $owner = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-equipe.test',
        );

        $inviteResponse = $this
            ->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->postJson($this->tenantUrl($tenant, '/tenant-users/invitations'), [
                'name' => 'Recepcao Convite',
                'email' => 'recepcao@barbearia-equipe.test',
                'role' => 'receptionist',
            ]);

        $invitationToken = $inviteResponse
            ->assertStatus(201)
            ->assertJsonPath('data.membership.role', 'receptionist')
            ->assertJsonPath('data.membership.status', 'invited')
            ->json('data.invitation.token');

        $this->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->getJson($this->tenantUrl($tenant, '/tenant-users/audits'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'tenant_user.invited');

        $acceptedToken = $this->postJson($this->tenantUrl($tenant, '/tenant-users/invitations/accept'), [
            'token' => $invitationToken,
            'password' => 'password123',
            'device_name' => 'convite-web',
        ])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'active')
            ->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$acceptedToken)
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertOk()
            ->assertJsonPath('data.membership.role', 'receptionist')
            ->assertJsonPath('data.user.email', 'recepcao@barbearia-equipe.test');
    }

    public function test_manager_can_update_membership_role_and_custom_permissions(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-permissoes',
            domain: 'barbearia-permissoes.test',
        );

        $manager = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'manager@barbearia-permissoes.test',
        );
        $targetUser = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            email: 'recepcao@barbearia-permissoes.test',
        );
        $membershipId = $this->membershipIdFor($tenant, $targetUser);

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $manager))
            ->patchJson($this->tenantUrl($tenant, "/tenant-users/{$membershipId}"), [
                'permissions_json' => ['tenant.users.read'],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions_json.0', 'tenant.users.read')
            ->assertJsonPath('data.status', 'active');

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $manager))
            ->getJson($this->tenantUrl($tenant, '/tenant-users/audits'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'tenant_user.membership_updated');

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist', user: $targetUser))
            ->getJson($this->tenantUrl($tenant, '/tenant-users'))
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'recepcao@barbearia-permissoes.test',
            ]);
    }

    public function test_receptionist_cannot_manage_tenant_users(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-bloqueio-usuarios',
            domain: 'barbearia-bloqueio-usuarios.test',
        );

        $receptionist = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            email: 'recepcao@barbearia-bloqueio-usuarios.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist', user: $receptionist))
            ->postJson($this->tenantUrl($tenant, '/tenant-users/invitations'), [
                'email' => 'novo@barbearia-bloqueio-usuarios.test',
                'role' => 'receptionist',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.');
    }

    public function test_password_reset_invalidates_old_token_and_returns_a_temporary_password(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reset',
            domain: 'barbearia-reset.test',
        );

        $owner = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-reset.test',
        );
        $targetUser = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-reset.test',
            password: 'password123',
        );
        $membershipId = $this->membershipIdFor($tenant, $targetUser);
        $oldToken = $this->issueTenantAccessToken($tenant, $targetUser);

        $temporaryPassword = $this
            ->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->postJson($this->tenantUrl($tenant, "/tenant-users/{$membershipId}/reset-password"), [])
            ->assertOk()
            ->assertJsonPath('data.membership.user.email', 'gestor@barbearia-reset.test')
            ->json('data.temporary_password');

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token de acesso ausente ou inválido.');

        $this->postJson($this->tenantUrl($tenant, '/auth/login'), [
            'email' => 'gestor@barbearia-reset.test',
            'password' => $temporaryPassword,
        ])->assertStatus(201);

        $this->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->getJson($this->tenantUrl($tenant, '/tenant-users/audits'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'tenant_user.password_reset');
    }

    private function membershipIdFor(Tenant $tenant, User $user): string
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->id;
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
