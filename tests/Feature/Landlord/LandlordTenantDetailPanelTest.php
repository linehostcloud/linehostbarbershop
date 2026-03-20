<?php

namespace Tests\Feature\Landlord;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantDetailPanelTest extends TestCase
{
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
        config()->set('tenancy.provisioning.database_prefix', $this->testDatabaseDirectory.DIRECTORY_SEPARATOR.'tenant_');
        config()->set('tenancy.provisioning.default_domain_suffix', 'saas.test');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'saas.test');
    }

    public function test_landlord_tenant_detail_requires_authentication(): void
    {
        $tenant = $this->provisionTenant('barbearia-detalhe-guest', 'barbearia-detalhe-guest.saas.test');

        $this->get(route('landlord.tenants.show', $tenant))
            ->assertRedirect(route('login'));
    }

    public function test_landlord_tenant_detail_displays_correct_tenant_and_operational_status(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-detalhe', 'barbearia-detalhe.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-outra', 'barbearia-outra.saas.test');
        $this->createTenantUser($tenant, email: 'owner-detalhe@test.local');
        $this->createTenantUser($otherTenant, email: 'owner-outra@test.local');

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Barbearia Detalhe')
            ->assertSee('barbearia-detalhe')
            ->assertSee('Status operacional detalhado')
            ->assertSee('Banco do tenant')
            ->assertSee('Schema mínimo')
            ->assertSee('Automações default')
            ->assertSee('owner-detalhe@test.local')
            ->assertDontSee('barbearia-outra');
    }

    public function test_landlord_tenant_detail_reports_schema_pending_when_minimum_schema_is_missing(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createLandlordTenantWithoutSchema('barbearia-schema-pendente', 'barbearia-schema-pendente.saas.test');

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Schema pendente')
            ->assertSee('Tabelas mínimas ausentes')
            ->assertSee('clients')
            ->assertSee('appointments')
            ->assertSee('messages');
    }

    public function test_landlord_can_sync_tenant_schema_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createLandlordTenantWithoutSchema('barbearia-schema-sync', 'barbearia-schema-sync.saas.test');

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.sync-schema', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertTrue(Schema::connection('tenant')->hasTable('clients'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('appointments'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('messages'));
            $this->assertSame(count(WhatsappAutomationType::values()), Automation::query()->count());
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.schema_sync_requested')->count());
    }

    public function test_landlord_can_ensure_default_automations_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-defaults', 'barbearia-defaults.saas.test');
        $this->createTenantUser($tenant, email: 'owner-defaults@test.local');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            Automation::query()->delete();
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.ensure-default-automations', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertSame(count(WhatsappAutomationType::values()), Automation::query()->count());
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.default_automations_ensured')->count());
    }

    public function test_landlord_tenant_detail_requires_central_domain(): void
    {
        config()->set('tenancy.central_domains', ['central.saas.test']);

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-central-domain', 'barbearia-central-domain.saas.test');

        $this->actingAs($admin)
            ->withServerVariables(['HTTP_HOST' => 'tenant.saas.test'])
            ->get(route('landlord.tenants.show', $tenant, false))
            ->assertNotFound();
    }

    public function test_non_admin_user_cannot_execute_landlord_detail_actions(): void
    {
        $user = User::factory()->create([
            'name' => 'Operador Sem Acesso',
            'email' => 'operador-sem-acesso@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
        $tenant = $this->provisionTenant('barbearia-bloqueio', 'barbearia-bloqueio.saas.test');

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.sync-schema', $tenant))
            ->assertForbidden();
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

    private function createLandlordTenantWithoutSchema(string $slug, string $domain): Tenant
    {
        $databasePath = $this->trackTenantDatabase(sprintf('%s.sqlite', $slug));
        touch($databasePath);
        chmod($databasePath, 0666);

        $tenant = Tenant::query()->create([
            'legal_name' => 'BARBEARIA SCHEMA LTDA',
            'trade_name' => str($slug)->replace('-', ' ')->title()->value(),
            'slug' => $slug,
            'niche' => 'barbershop',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'status' => 'active',
            'onboarding_stage' => 'created',
            'database_name' => $databasePath,
            'database_host' => null,
            'database_port' => null,
            'database_username' => null,
            'database_password_encrypted' => null,
            'plan_code' => 'starter',
            'activated_at' => now(),
        ]);

        $tenant->domains()->create([
            'domain' => $domain,
            'type' => 'admin',
            'is_primary' => true,
            'ssl_status' => 'pending',
        ]);

        $owner = User::factory()->create([
            'name' => 'Owner Schema',
            'email' => sprintf('%s@schema.test', $slug),
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_primary' => true,
            'accepted_at' => now(),
        ]);

        return $tenant->fresh(['domains', 'memberships.user']);
    }
}
