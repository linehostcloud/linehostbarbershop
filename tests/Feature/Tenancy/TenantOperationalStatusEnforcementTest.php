<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Tests\Concerns\InteractsWithTenantWhatsappPanel;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantOperationalStatusEnforcementTest extends TestCase
{
    use InteractsWithTenantWhatsappPanel;
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
    }

    public function test_active_tenant_keeps_access_to_protected_web_and_api_flows(): void
    {
        $tenant = $this->provisionTenant('barbearia-status-active', 'barbearia-status-active.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-status-active.test',
            password: 'password123',
        );

        $panelCookie = $this->cookieValue(
            $this->postPanelLogin($tenant, $user->email, 'password123')->assertRedirect($this->panelRelationshipUrl($tenant)),
            (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'),
        );

        $this->assertNotNull($panelCookie);

        $this->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), (string) $panelCookie)
            ->get($this->panelUrl($tenant))
            ->assertOk()
            ->assertSee('Mensageria WhatsApp');

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user))
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_suspended_tenant_is_blocked_on_tenant_web_routes(): void
    {
        $tenant = $this->provisionTenant('barbearia-status-web', 'barbearia-status-web.test');
        $this->suspendTenant($tenant);

        $this->get($this->panelLoginUrl($tenant))
            ->assertStatus(423)
            ->assertSee('Tenant suspenso para operacao');

        $this->get($this->panelUrl($tenant))
            ->assertStatus(423)
            ->assertSee('Tenant suspenso para operacao');
    }

    public function test_suspended_tenant_is_blocked_on_protected_tenant_api_routes(): void
    {
        $tenant = $this->provisionTenant('barbearia-status-api', 'barbearia-status-api.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-status-api.test',
        );
        $this->suspendTenant($tenant);

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner', user: $user))
            ->getJson($this->tenantUrl($tenant, '/auth/me'))
            ->assertStatus(423)
            ->assertJsonPath('message', 'O tenant "Barbearia Status Api" esta suspenso e nao pode operar no momento.')
            ->assertJsonPath('tenant_status', 'suspended');
    }

    public function test_suspended_tenant_is_rejected_on_whatsapp_boundary_and_audited(): void
    {
        $tenant = $this->provisionTenant('barbearia-status-boundary', 'barbearia-status-boundary.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-status-boundary.test',
        );
        $this->suspendTenant($tenant);

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user))
            ->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
                'client_id' => 'cli_fake',
                'provider' => 'fake',
                'body_text' => 'Nao deveria passar pela borda operacional.',
            ])
            ->assertStatus(423)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('boundary_rejection_code', 'security_policy_violation')
            ->assertJsonPath('tenant_status', 'suspended');

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('security_policy_violation', $audit->code);
        $this->assertSame('outbound', $audit->direction);
        $this->assertSame('suspended', data_get($audit->context_json, 'tenant_status'));

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, Client::query()->count());
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
        });
    }

    public function test_landlord_admin_access_is_not_blocked_for_suspended_tenants(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-status-landlord', 'barbearia-status-landlord.test');
        $this->suspendTenant($tenant);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Barbearia Status Landlord')
            ->assertSee('Suspenso');
    }

    public function test_suspended_tenant_runtime_commands_do_not_process_outbox_automations_or_agent(): void
    {
        $tenant = $this->provisionTenant('barbearia-status-runtime', 'barbearia-status-runtime.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-status-runtime.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Runtime Suspenso',
            'phone_e164' => '+5511999996499',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Mensagem que deve ficar parada enquanto o tenant estiver suspenso.',
        ])->assertCreated()->json('data.id');

        $this->suspendTenant($tenant);

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->artisan('tenancy:process-whatsapp-automations', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->artisan('tenancy:run-whatsapp-agent', [
            '--tenant' => [$tenant->slug],
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('pending', $outboxEvent->status);
            $this->assertSame(0, $outboxEvent->attempt_count);
            $this->assertSame(0, $outboxEvent->integrationAttempts()->count());
            $this->assertSame(0, AutomationRun::query()->count());
            $this->assertSame(0, AgentRun::query()->count());
            $this->assertSame(0, AgentInsight::query()->count());
        });
    }

    private function createLandlordAdmin(): User
    {
        return User::factory()->create([
            'name' => 'SaaS Admin',
            'email' => 'saas-admin@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
    }

    private function suspendTenant(Tenant $tenant): void
    {
        $tenant->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
        ])->save();
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenantConnection(Tenant $tenant, \Closure $callback): mixed
    {
        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            return $callback();
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
