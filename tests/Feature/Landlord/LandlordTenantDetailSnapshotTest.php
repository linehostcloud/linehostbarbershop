<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\BuildLandlordTenantDetailSnapshotPayloadAction;
use App\Application\Actions\Tenancy\DetermineLandlordTenantProvisioningStatusAction;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantDetailSnapshotTest extends TestCase
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

    public function test_landlord_tenant_detail_reads_snapshot_when_available_without_live_inspection(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-snapshot-hit', 'barbearia-snapshot-hit.saas.test');
        $this->createTenantUser($tenant, email: 'owner-snapshot-hit@test.local');

        $this->createSnapshot($tenant, generatedAt: now()->subMinutes(3));

        $this->app->bind(DetermineLandlordTenantProvisioningStatusAction::class, fn (): DetermineLandlordTenantProvisioningStatusAction => new class extends DetermineLandlordTenantProvisioningStatusAction
        {
            public function __construct()
            {
            }

            public function execute(Tenant $tenant): array
            {
                throw new RuntimeException('Leitura real de provisioning não deveria rodar no show snapshot-first.');
            }
        });

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Snapshot atualizado')
            ->assertSee('Provisionado')
            ->assertSee('Provisioning: snapshot')
            ->assertSee('Saúde: snapshot')
            ->assertSee('Hardening: snapshot')
            ->assertSee('Recorrência detectada de bloqueios operacionais durante a suspensão.')
            ->assertSee('Total auditado: 5 evento(s).');
    }

    public function test_landlord_tenant_detail_falls_back_safely_when_snapshot_is_missing_without_live_inspection(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-snapshot-miss', 'barbearia-snapshot-miss.saas.test');
        $this->createTenantUser($tenant, email: 'owner-snapshot-miss@test.local');

        $this->app->bind(DetermineLandlordTenantProvisioningStatusAction::class, fn (): DetermineLandlordTenantProvisioningStatusAction => new class extends DetermineLandlordTenantProvisioningStatusAction
        {
            public function __construct()
            {
            }

            public function execute(Tenant $tenant): array
            {
                throw new RuntimeException('A leitura do detalhe não deveria cair em provisioning real sem snapshot.');
            }
        });

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Snapshot administrativo pendente')
            ->assertSee('Snapshot pendente')
            ->assertSee('Provisioning: fallback')
            ->assertSee('Saúde: fallback')
            ->assertSee('Hardening: fallback')
            ->assertSee('Snapshot de hardening indisponível');
    }

    public function test_landlord_tenant_detail_flags_stale_snapshot(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-snapshot-stale', 'barbearia-snapshot-stale.saas.test');
        $this->createTenantUser($tenant, email: 'owner-snapshot-stale@test.local');

        $this->createSnapshot($tenant, generatedAt: now()->subMinutes(30));

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Snapshot stale')
            ->assertSee('Status: stale.');
    }

    public function test_landlord_can_refresh_tenant_detail_snapshot_manually(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-refresh-manual', 'barbearia-refresh-manual.saas.test');
        $this->createTenantUser($tenant, email: 'owner-refresh-manual@test.local');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.refresh-detail-snapshot', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status.message', 'Snapshot administrativo do tenant "Barbearia Refresh Manual" atualizado com sucesso.');

        $snapshot = LandlordTenantDetailSnapshot::query()->where('tenant_id', $tenant->id)->sole();

        $this->assertSame('ready', $snapshot->refresh_status);
        $this->assertNotNull($snapshot->generated_at);
        $this->assertIsArray($snapshot->payload_json);
        $this->assertArrayHasKey('provisioning', $snapshot->payload_json);
        $this->assertArrayHasKey('operational', $snapshot->payload_json);
        $this->assertArrayHasKey('suspension_observability', $snapshot->payload_json);
    }

    public function test_landlord_manual_snapshot_refresh_respects_refresh_lock(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-refresh-lock', 'barbearia-refresh-lock.saas.test');
        $this->createTenantUser($tenant, email: 'owner-refresh-lock@test.local');

        $lockKey = app(TenantExecutionLockManager::class)
            ->lockKeyForTenant($tenant, 'landlord_tenant_detail_snapshot_refresh');
        $lock = Cache::lock($lockKey, 300);

        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->from(route('landlord.tenants.show', $tenant))
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->post(route('landlord.tenants.refresh-detail-snapshot', $tenant))
                ->assertRedirect(route('landlord.tenants.show', $tenant))
                ->assertSessionHas('status.message', 'Já existe um refresh de snapshot em andamento para o tenant "Barbearia Refresh Lock".');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, LandlordTenantDetailSnapshot::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_landlord_manual_snapshot_refresh_marks_snapshot_failed_when_generation_fails(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-refresh-falha', 'barbearia-refresh-falha.saas.test');
        $this->createTenantUser($tenant, email: 'owner-refresh-falha@test.local');
        $existingSnapshot = $this->createSnapshot($tenant, generatedAt: now()->subMinutes(20));

        $this->app->bind(BuildLandlordTenantDetailSnapshotPayloadAction::class, fn (): BuildLandlordTenantDetailSnapshotPayloadAction => new class extends BuildLandlordTenantDetailSnapshotPayloadAction
        {
            public function __construct()
            {
            }

            public function execute(Tenant $tenant): array
            {
                throw new RuntimeException('Falha sintética ao gerar snapshot.');
            }
        });

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.refresh-detail-snapshot', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status.message', 'Falha sintética ao gerar snapshot.');

        $snapshot = $existingSnapshot->fresh();

        $this->assertSame('failed', $snapshot->refresh_status);
        $this->assertSame('Falha sintética ao gerar snapshot.', $snapshot->last_refresh_error);
        $this->assertNotNull($snapshot->last_refresh_failed_at);
        $this->assertIsArray($snapshot->payload_json);
        $this->assertArrayHasKey('provisioning', $snapshot->payload_json);
    }

    public function test_landlord_snapshot_refresh_command_updates_only_stale_snapshots_when_requested(): void
    {
        $freshTenant = $this->provisionTenant('barbearia-snapshot-fresh', 'barbearia-snapshot-fresh.saas.test');
        $this->createTenantUser($freshTenant, email: 'owner-snapshot-fresh@test.local');
        $staleTenant = $this->provisionTenant('barbearia-snapshot-command', 'barbearia-snapshot-command.saas.test');
        $this->createTenantUser($staleTenant, email: 'owner-snapshot-command@test.local');

        $freshSnapshot = $this->createSnapshot($freshTenant, generatedAt: now()->subMinutes(2));

        $this->artisan('landlord:refresh-tenant-detail-snapshots', [
            '--stale-only' => true,
        ])
            ->expectsOutput(sprintf('[%s] snapshot atualizado com sucesso.', $staleTenant->slug))
            ->expectsOutput('Tenant detail snapshots: refreshed=1 skipped_fresh=1 skipped_locked=0 failed=0')
            ->assertExitCode(0);

        $this->assertNotNull(
            LandlordTenantDetailSnapshot::query()->where('tenant_id', $staleTenant->id)->value('generated_at')
        );
        $this->assertSame(
            $freshSnapshot->generated_at?->toIso8601String(),
            $freshSnapshot->fresh()->generated_at?->toIso8601String(),
        );
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

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    private function createSnapshot(Tenant $tenant, array $payloadOverrides = [], ?\DateTimeInterface $generatedAt = null): LandlordTenantDetailSnapshot
    {
        $generatedAt ??= now();

        return LandlordTenantDetailSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'refresh_status' => 'ready',
            'last_refresh_source' => 'test',
            'payload_json' => array_replace_recursive([
                'provisioning' => [
                    'code' => 'provisioned',
                    'label' => 'Provisionado',
                    'detail' => 'Banco, schema, domínio principal e owner ativo estão prontos.',
                    'schema_ok' => true,
                    'database_exists' => true,
                    'owner_ready' => true,
                    'domain_ready' => true,
                ],
                'operational' => [
                    'checks' => [
                        ['key' => 'database', 'label' => 'Banco do tenant', 'ok' => true, 'detail' => 'O banco do tenant está acessível para operação.'],
                        ['key' => 'schema', 'label' => 'Schema mínimo', 'ok' => true, 'detail' => 'As tabelas mínimas do tenant estão presentes.'],
                        ['key' => 'primary_domain', 'label' => 'Domínio principal', 'ok' => true, 'detail' => 'Há um domínio principal configurado para o tenant.'],
                        ['key' => 'owner', 'label' => 'Owner ativo', 'ok' => true, 'detail' => 'Existe um owner ativo vinculado ao tenant.'],
                        ['key' => 'automation_defaults', 'label' => 'Automações default', 'ok' => true, 'detail' => 'As automações padrão de WhatsApp estão disponíveis.'],
                        ['key' => 'basic_data', 'label' => 'Dados básicos mínimos', 'ok' => true, 'detail' => 'Nome fantasia, razão social, timezone e moeda estão preenchidos.'],
                    ],
                    'schema_missing_tables' => [],
                    'summary' => [
                        'ok_count' => 6,
                        'total_count' => 6,
                        'pending_count' => 0,
                    ],
                ],
                'suspension_observability' => [
                    'availability' => [
                        'available' => true,
                        'label' => null,
                        'detail' => null,
                        'missing_tables' => [],
                    ],
                    'access_tokens' => [
                        'active_count' => 2,
                        'last_revoked_count' => 1,
                        'last_revoked_at' => '21/03/2026 10:00',
                    ],
                    'summary' => [
                        'window_label' => 'Últimos 7 dias',
                        'total_count' => 5,
                        'affected_channels_count' => 3,
                        'recurring' => true,
                        'recurring_label' => 'Recorrência detectada de bloqueios operacionais durante a suspensão.',
                    ],
                    'channels' => [
                        ['channel' => 'api', 'label' => 'API tenant bloqueada', 'count' => 2, 'last_seen_at' => '21/03/2026 09:30'],
                        ['channel' => 'webhook', 'label' => 'Webhooks ignorados', 'count' => 2, 'last_seen_at' => '21/03/2026 08:30'],
                        ['channel' => 'outbound', 'label' => 'API outbound bloqueada', 'count' => 1, 'last_seen_at' => '21/03/2026 07:30'],
                    ],
                    'recent_blocks' => [
                        ['id' => 'block-1', 'channel' => 'api', 'label' => 'API tenant bloqueada', 'detail' => 'GET api/v1/auth/me', 'occurred_at' => '21/03/2026 09:30'],
                    ],
                    'webhook_policy' => [
                        'status_code' => 202,
                        'label' => 'Webhook suspenso reconhecido sem processamento',
                        'detail' => 'Webhooks recebidos durante a suspensão retornam 202 e são auditados como ignorados para evitar retries contínuos desnecessários.',
                    ],
                ],
            ], $payloadOverrides),
            'generated_at' => $generatedAt,
            'last_refresh_started_at' => $generatedAt,
            'last_refresh_completed_at' => $generatedAt,
        ]);
    }
}
