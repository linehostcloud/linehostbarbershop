<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\BuildLandlordTenantIndexReadContextAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantSuspendedPressureAction;
use App\Application\Actions\Tenancy\DetermineLandlordTenantProvisioningStatusAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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

    public function test_landlord_panel_displays_dashboard_summary_metrics_and_priority_lists(): void
    {
        $admin = $this->createLandlordAdmin();
        $activeTenant = $this->provisionTenant('barbearia-ativa-painel', 'barbearia-ativa-painel.saas.test');
        $this->createTenantUser($activeTenant, email: 'owner-ativo-painel@test.local');
        $activeTenant->forceFill([
            'status' => 'active',
            'onboarding_stage' => 'provisioned',
        ])->save();

        $pendingTenant = $this->createPendingTenant('barbearia-trial-pendente-painel');
        $suspendedTenant = $this->provisionTenant('barbearia-suspensa-painel', 'barbearia-suspensa-painel.saas.test');
        $this->createTenantUser($suspendedTenant, email: 'owner-suspenso-painel@test.local');
        $suspendedTenant->forceFill([
            'status' => 'suspended',
            'onboarding_stage' => 'completed',
            'suspended_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'action' => 'landlord_tenant.status_changed',
            'before_json' => ['status' => 'active'],
            'after_json' => ['status' => 'suspended'],
            'metadata_json' => ['reason' => 'Suspensão manual para revisão.'],
        ]);

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'api',
            'outcome' => 'blocked',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'endpoint' => 'api/v1/auth/me',
            'method' => 'GET',
            'http_status' => 423,
            'correlation_id' => 'tenant-painel-api-001',
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(30),
        ]);

        BoundaryRejectionAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'direction' => 'webhook',
            'endpoint' => 'webhooks/whatsapp/fake',
            'method' => 'POST',
            'host' => 'barbearia-suspensa-painel.saas.test',
            'code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
            'message' => 'Webhook ignorado porque o tenant está suspenso.',
            'http_status' => 202,
            'correlation_id' => 'tenant-painel-webhook-001',
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertOk()
            ->assertSee('Resumo landlord')
            ->assertSee('Total de tenants')
            ->assertSee('Status administrativo')
            ->assertSee('Onboarding')
            ->assertSee('Pendências operacionais básicas')
            ->assertSee('Suspensos com pressão recente')
            ->assertSee('Atividade administrativa recente')
            ->assertSee('Atenção prioritária')
            ->assertSee('Barbearia Trial Pendente Painel')
            ->assertSee('Banco pendente')
            ->assertSee('Barbearia Suspensa Painel')
            ->assertSee('Status do tenant atualizado')
            ->assertSee('API tenant bloqueada')
            ->assertSee('Webhooks ignorados')
            ->assertSee('Barbearia Ativa Painel');
    }

    public function test_landlord_panel_filters_listing_via_query_string(): void
    {
        $admin = $this->createLandlordAdmin();
        $pendingTenant = $this->createPendingTenant('barbearia-trial-filtro-lista');
        $activeTenant = $this->createProvisionedTenantForPanel(
            slug: 'barbearia-ativa-filtro-lista',
            status: 'active',
            onboardingStage: 'completed',
        );
        $suspendedTenant = $this->createProvisionedTenantForPanel(
            slug: 'barbearia-suspensa-filtro-lista',
            status: 'suspended',
            onboardingStage: 'provisioned',
        );

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'api',
            'outcome' => 'blocked',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'endpoint' => 'api/v1/auth/me',
            'method' => 'GET',
            'http_status' => 423,
            'correlation_id' => 'tenant-filtro-api-001',
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(10),
        ]);

        $statusResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['status' => 'trial']));
        $statusResponse->assertOk();
        $this->assertSame(
            [$pendingTenant->slug],
            $statusResponse->viewData('tenants')->getCollection()->pluck('slug')->all(),
        );
        $this->assertSame('trial', $statusResponse->viewData('filters')['status']);

        $onboardingResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['onboarding_stage' => 'completed']));
        $onboardingResponse->assertOk();
        $this->assertSame(
            [$activeTenant->slug],
            $onboardingResponse->viewData('tenants')->getCollection()->pluck('slug')->all(),
        );

        $pendingResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['provisioning' => 'pending']));
        $pendingResponse->assertOk();
        $this->assertSame(
            [$pendingTenant->slug],
            $pendingResponse->viewData('tenants')->getCollection()->pluck('slug')->all(),
        );

        $pressureResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['pressure' => 'suspended_recent']));
        $pressureResponse->assertOk();
        $this->assertSame(
            [$suspendedTenant->slug],
            $pressureResponse->viewData('tenants')->getCollection()->pluck('slug')->all(),
        );
        $this->assertSame('suspended_recent', $pressureResponse->viewData('filters')['pressure']);
    }

    public function test_landlord_panel_renders_actionable_dashboard_links(): void
    {
        $admin = $this->createLandlordAdmin();
        $pendingTenant = $this->createPendingTenant('barbearia-link-pendente');
        $suspendedTenant = $this->createProvisionedTenantForPanel(
            slug: 'barbearia-link-suspensa',
            status: 'suspended',
            onboardingStage: 'completed',
        );

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'api',
            'outcome' => 'blocked',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'endpoint' => 'api/v1/auth/me',
            'method' => 'GET',
            'http_status' => 423,
            'correlation_id' => 'tenant-link-api-001',
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertOk()
            ->assertSee(route('landlord.tenants.index', ['status' => 'trial']), false)
            ->assertSee(route('landlord.tenants.index', ['onboarding_stage' => 'created']), false)
            ->assertSee(route('landlord.tenants.index', ['provisioning' => 'pending']), false)
            ->assertSee(route('landlord.tenants.index', ['provisioning' => 'database_missing']), false)
            ->assertSee(route('landlord.tenants.index', ['pressure' => 'suspended_recent']), false)
            ->assertSee(route('landlord.tenants.show', $pendingTenant), false)
            ->assertSee(route('landlord.tenants.show', $suspendedTenant), false);
    }

    public function test_landlord_panel_preserves_query_string_on_pagination_links(): void
    {
        $admin = $this->createLandlordAdmin();

        foreach (range(1, 17) as $index) {
            $tenant = $this->createPendingTenant(sprintf('barbearia-trial-paginacao-%02d', $index));
            $tenant->forceFill([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ])->save();
        }

        $response = $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['status' => 'trial']));
        $response->assertOk();

        $paginator = $response->viewData('tenants');

        $this->assertStringContainsString('status=trial', $paginator->url(2));

        $secondPageResponse = $this->actingAs($admin)->get($paginator->url(2));
        $secondPageResponse->assertOk();
        $this->assertSame('trial', $secondPageResponse->viewData('filters')['status']);
        $this->assertCount(2, $secondPageResponse->viewData('tenants')->getCollection());
    }

    public function test_landlord_panel_computes_provisioning_once_per_tenant_across_dashboard_and_listing(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createPendingTenant('barbearia-provisioning-unico-a');
        $this->createProvisionedTenantForPanel('barbearia-provisioning-unico-b');
        $this->createProvisionedTenantForPanel('barbearia-provisioning-unico-c', status: 'suspended');

        $delegate = app(DetermineLandlordTenantProvisioningStatusAction::class);
        $calls = 0;
        $increment = function () use (&$calls): void {
            $calls++;
        };

        $this->app->bind(DetermineLandlordTenantProvisioningStatusAction::class, fn (): DetermineLandlordTenantProvisioningStatusAction => new class($delegate, $increment) extends DetermineLandlordTenantProvisioningStatusAction
        {
            public function __construct(
                private readonly DetermineLandlordTenantProvisioningStatusAction $delegate,
                private readonly \Closure $increment,
            ) {
            }

            public function execute(Tenant $tenant): array
            {
                ($this->increment)();

                return $this->delegate->execute($tenant);
            }
        });

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertOk();

        $this->assertSame(3, $calls);
    }

    public function test_landlord_panel_computes_suspended_pressure_once_per_request(): void
    {
        $admin = $this->createLandlordAdmin();
        $suspendedTenant = $this->createProvisionedTenantForPanel(
            slug: 'barbearia-pressure-unico',
            status: 'suspended',
            onboardingStage: 'completed',
        );

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'api',
            'outcome' => 'blocked',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'endpoint' => 'api/v1/auth/me',
            'method' => 'GET',
            'http_status' => 423,
            'correlation_id' => 'tenant-pressure-unico-001',
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(5),
        ]);

        $delegate = app(BuildLandlordTenantSuspendedPressureAction::class);
        $calls = 0;
        $increment = function () use (&$calls): void {
            $calls++;
        };

        $this->app->bind(BuildLandlordTenantSuspendedPressureAction::class, fn (): BuildLandlordTenantSuspendedPressureAction => new class($delegate, $increment) extends BuildLandlordTenantSuspendedPressureAction
        {
            public function __construct(
                private readonly BuildLandlordTenantSuspendedPressureAction $delegate,
                private readonly \Closure $increment,
            ) {
            }

            public function execute(?Collection $tenants = null): Collection
            {
                ($this->increment)();

                return $this->delegate->execute($tenants);
            }
        });

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['pressure' => 'suspended_recent']))
            ->assertOk()
            ->assertSee('barbearia-pressure-unico');

        $this->assertSame(1, $calls);
    }

    public function test_landlord_panel_records_structured_performance_log(): void
    {
        config()->set('observability.landlord_tenants_index.performance_logging_enabled', true);

        $admin = $this->createLandlordAdmin();
        $this->createPendingTenant('barbearia-log-perf-pendente');
        $this->createProvisionedTenantForPanel('barbearia-log-perf-ativa');

        Log::spy();

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index', ['status' => 'active']))
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Landlord tenant index read measured.'
                    && data_get($context, 'event') === 'landlord.tenants.index.read'
                    && data_get($context, 'filters.status') === 'active'
                    && data_get($context, 'counts.tenant_count') === 2
                    && data_get($context, 'counts.filtered_tenant_count') === 1
                    && data_get($context, 'counts.provisioning_validation_count') === 2
                    && is_int(data_get($context, 'durations_ms.total_duration_ms'))
                    && is_int(data_get($context, 'durations_ms.summary_mapping_duration_ms'));
            })
            ->once();
    }

    public function test_landlord_panel_records_warning_when_landlord_read_fails(): void
    {
        config()->set('observability.landlord_tenants_index.performance_logging_enabled', true);

        $admin = $this->createLandlordAdmin();
        Log::spy();

        $this->app->bind(BuildLandlordTenantIndexReadContextAction::class, fn (): BuildLandlordTenantIndexReadContextAction => new class extends BuildLandlordTenantIndexReadContextAction
        {
            public function __construct()
            {
            }

            public function execute(): \App\Application\Actions\Tenancy\LandlordTenantIndexReadContext
            {
                throw new RuntimeException('Falha sintética de leitura landlord.');
            }
        });

        $this->actingAs($admin)
            ->get(route('landlord.tenants.index'))
            ->assertStatus(500);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Landlord tenant index read failed.'
                    && data_get($context, 'event') === 'landlord.tenants.index.read_failed'
                    && data_get($context, 'exception_class') === RuntimeException::class
                    && data_get($context, 'counts.technical_failure_count') === 1;
            })
            ->atLeast()
            ->once();
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

    private function createPendingTenant(string $slug): Tenant
    {
        $databasePath = $this->trackTenantDatabase(sprintf('tenant_%s.sqlite', str_replace('-', '_', $slug)));

        return Tenant::query()->create([
            'legal_name' => strtoupper(str_replace('-', ' ', $slug)).' LTDA',
            'trade_name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'niche' => 'barbershop',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'status' => 'trial',
            'onboarding_stage' => 'created',
            'database_name' => $databasePath,
            'database_host' => null,
            'database_port' => null,
            'database_username' => null,
            'database_password_encrypted' => null,
        ]);
    }

    private function createProvisionedTenantForPanel(
        string $slug,
        string $status = 'active',
        string $onboardingStage = 'completed',
    ): Tenant {
        $tenant = $this->provisionTenant($slug, sprintf('%s.saas.test', $slug));
        $this->createTenantUser($tenant, email: sprintf('%s-owner@test.local', $slug));

        $tenant->forceFill([
            'status' => $status,
            'onboarding_stage' => $onboardingStage,
            'suspended_at' => $status === 'suspended' ? now() : null,
        ])->save();

        return $tenant;
    }
}
