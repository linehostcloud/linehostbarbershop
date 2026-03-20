<?php

namespace Tests\Feature\Landlord;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantPanelTest extends TestCase
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

    public function test_landlord_panel_requires_authentication(): void
    {
        $this->get(route('landlord.tenants.index'))
            ->assertRedirect(route('login'));
    }

    public function test_landlord_panel_lists_existing_tenants(): void
    {
        $admin = $this->createLandlordAdmin();
        $firstTenant = $this->provisionTenant('barbearia-matriz', 'barbearia-matriz.saas.test');
        $secondTenant = $this->provisionTenant('barbearia-centro', 'barbearia-centro.saas.test');
        $this->createTenantUser($firstTenant, email: 'owner-matriz@test.local');
        $this->createTenantUser($secondTenant, email: 'owner-centro@test.local');

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertOk()
            ->assertSee('Tenants')
            ->assertSee('Barbearia Matriz')
            ->assertSee('barbearia-centro')
            ->assertSee('Provisionado');
    }

    public function test_non_admin_user_cannot_access_landlord_panel(): void
    {
        $user = User::factory()->create([
            'name' => 'Usuario Comum',
            'email' => 'usuario-comum@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);

        $this->actingAs($user)
            ->get(route('landlord.tenants.index'))
            ->assertForbidden()
            ->assertSee('não tem acesso ao painel SaaS');
    }

    public function test_landlord_can_create_tenant_via_web_and_provision_schema(): void
    {
        $admin = $this->createLandlordAdmin();

        $response = $this->actingAs($admin)
            ->from(route('landlord.tenants.create'))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.store'), [
                'trade_name' => 'Barbearia Ouro',
                'legal_name' => 'Barbearia Ouro LTDA',
                'slug' => 'barbearia-ouro',
                'domain' => '',
                'owner_name' => 'Owner Ouro',
                'owner_email' => 'owner@ouro.test',
            ]);

        $response->assertRedirect(route('landlord.tenants.index'));

        $tenant = Tenant::query()
            ->where('slug', 'barbearia-ouro')
            ->with(['domains', 'memberships.user'])
            ->firstOrFail();

        $this->assertSame('barbearia-ouro.saas.test', $tenant->domains()->value('domain'));
        $this->assertSame('provisioned', $tenant->onboarding_stage);
        $this->assertTrue($tenant->memberships()->where('role', 'owner')->exists());
        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.provisioned_via_web')->count());

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertTrue(Schema::connection('tenant')->hasTable('clients'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('appointments'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('messages'));
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertOk()
            ->assertSee('Tenant &quot;Barbearia Ouro&quot; provisionado com sucesso.', false)
            ->assertSee('barbearia-ouro')
            ->assertSee('Senha temporária')
            ->assertSee('owner@ouro.test');
    }

    public function test_landlord_tenant_creation_validates_input(): void
    {
        $admin = $this->createLandlordAdmin();

        $this->actingAs($admin)
            ->from(route('landlord.tenants.create'))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.store'), [
                'trade_name' => '',
                'slug' => 'Slug Invalido',
                'owner_name' => '',
                'owner_email' => 'email-invalido',
            ])
            ->assertRedirect(route('landlord.tenants.create'))
            ->assertSessionHasErrors(['trade_name', 'slug', 'owner_name', 'owner_email']);

        $this->assertDatabaseCount('tenants', 0, 'landlord');
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
}
