<?php

namespace Tests\Feature\Landlord;

use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantSnapshotDashboardTest extends TestCase
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

    public function test_landlord_snapshot_dashboard_requires_authentication(): void
    {
        $this->get(route('landlord.tenants.snapshots'))
            ->assertRedirect(route('login'));
    }

    public function test_landlord_snapshot_dashboard_displays_aggregated_snapshot_health_and_priorities(): void
    {
        $admin = $this->createLandlordAdmin();

        $missingTenant = $this->provisionTenant('alpha-missing', 'alpha-missing.saas.test');
        $failedTenant = $this->provisionTenant('bravo-failed', 'bravo-failed.saas.test');
        $staleTenant = $this->provisionTenant('charlie-stale', 'charlie-stale.saas.test');
        $refreshingTenant = $this->provisionTenant('delta-refreshing', 'delta-refreshing.saas.test');
        $healthyTenant = $this->provisionTenant('echo-healthy', 'echo-healthy.saas.test');

        $failedTenant->forceFill(['status' => 'suspended'])->save();
        $this->createSnapshot($failedTenant, [
            'refresh_status' => 'failed',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_failed_at' => now()->subMinutes(10),
            'last_refresh_error' => 'Falha no refresh.',
        ]);
        $this->createSnapshot($staleTenant, [
            'refresh_status' => 'ready',
            'generated_at' => now()->subMinutes(50),
        ]);
        $this->createSnapshot($refreshingTenant, [
            'refresh_status' => 'refreshing',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_started_at' => now()->subMinutes(5),
        ]);
        $this->createSnapshot($healthyTenant, [
            'refresh_status' => 'ready',
            'generated_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots'));

        $response
            ->assertOk()
            ->assertSee('Saúde dos snapshots')
            ->assertSee('Falha no refresh.')
            ->assertSee('Fallback')
            ->assertSee('Alta')
            ->assertSee('Baixa')
            ->assertViewHas('headline', function (array $headline): bool {
                return $headline['total_monitored'] === 5
                    && $headline['healthy_count'] === 1
                    && $headline['stale_count'] === 1
                    && $headline['missing_count'] === 1
                    && $headline['failed_count'] === 1
                    && $headline['refreshing_count'] === 1
                    && $headline['fallback_count'] === 3;
            })
            ->assertViewHas('tenants', function ($paginator): bool {
                $items = collect($paginator->items());

                return $items->count() === 5
                    && data_get($items->first(), 'tenant.trade_name') === 'Alpha Missing'
                    && $items->pluck('tenant.trade_name')->contains('Bravo Failed')
                    && $items->pluck('tenant.trade_name')->contains('Echo Healthy');
            });
    }

    public function test_landlord_snapshot_dashboard_filters_by_snapshot_status_tenant_status_and_search(): void
    {
        $admin = $this->createLandlordAdmin();

        $failedTenant = $this->provisionTenant('falha-busca', 'falha-busca.saas.test');
        $failedTenant->forceFill(['status' => 'suspended'])->save();
        $this->createSnapshot($failedTenant, [
            'refresh_status' => 'failed',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_failed_at' => now()->subMinutes(8),
            'last_refresh_error' => 'Falha buscada.',
        ]);

        $healthyTenant = $this->provisionTenant('saudavel-busca', 'saudavel-busca.saas.test');
        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots', [
                'snapshot_status' => 'failed',
                'tenant_status' => 'suspended',
                'search' => 'falha-busca',
            ]));

        $response
            ->assertOk()
            ->assertSee('Falha Busca')
            ->assertDontSee('Saudavel Busca')
            ->assertViewHas('tenants', function ($paginator): bool {
                $items = collect($paginator->items());

                return $items->count() === 1
                    && data_get($items->first(), 'tenant.slug') === 'falha-busca'
                    && data_get($items->first(), 'snapshot_status.code') === 'failed';
            });
    }

    public function test_landlord_snapshot_dashboard_supports_sorting_by_tenant_and_snapshot_age(): void
    {
        $admin = $this->createLandlordAdmin();

        $zeta = $this->provisionTenant('zeta-ordenacao', 'zeta-ordenacao.saas.test');
        $alpha = $this->provisionTenant('alpha-ordenacao', 'alpha-ordenacao.saas.test');
        $mid = $this->provisionTenant('mid-ordenacao', 'mid-ordenacao.saas.test');

        $this->createSnapshot($zeta, ['generated_at' => now()->subMinutes(2)]);
        $this->createSnapshot($alpha, ['generated_at' => now()->subHours(3)]);
        $this->createSnapshot($mid, ['generated_at' => now()->subMinutes(45)]);

        $tenantSortedResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots', [
                'sort' => 'tenant',
                'direction' => 'desc',
            ]));

        $tenantSortedResponse->assertViewHas('tenants', function ($paginator): bool {
            return collect($paginator->items())
                ->pluck('tenant.trade_name')
                ->values()
                ->all() === ['Zeta Ordenacao', 'Mid Ordenacao', 'Alpha Ordenacao'];
        });

        $ageSortedResponse = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots', [
                'sort' => 'snapshot_age',
                'direction' => 'desc',
            ]));

        $ageSortedResponse->assertViewHas('tenants', function ($paginator): bool {
            return collect($paginator->items())
                ->pluck('tenant.trade_name')
                ->values()
                ->all() === ['Alpha Ordenacao', 'Mid Ordenacao', 'Zeta Ordenacao'];
        });
    }

    public function test_landlord_snapshot_dashboard_paginates_results(): void
    {
        config()->set('landlord.tenants.list_per_page', 2);

        $admin = $this->createLandlordAdmin();

        $alpha = $this->provisionTenant('alpha-pagina', 'alpha-pagina.saas.test');
        $bravo = $this->provisionTenant('bravo-pagina', 'bravo-pagina.saas.test');
        $charlie = $this->provisionTenant('charlie-pagina', 'charlie-pagina.saas.test');

        $this->createSnapshot($alpha, ['generated_at' => now()->subMinutes(10)]);
        $this->createSnapshot($bravo, ['generated_at' => now()->subMinutes(9)]);
        $this->createSnapshot($charlie, ['generated_at' => now()->subMinutes(8)]);

        $pageOne = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots', [
                'sort' => 'tenant',
                'direction' => 'asc',
            ]));

        $pageOne
            ->assertOk()
            ->assertSee('Alpha Pagina')
            ->assertSee('Bravo Pagina')
            ->assertDontSee('Charlie Pagina')
            ->assertViewHas('tenants', fn ($paginator): bool => $paginator->total() === 3 && $paginator->currentPage() === 1);

        $pageTwo = $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots', [
                'sort' => 'tenant',
                'direction' => 'asc',
                'page' => 2,
            ]));

        $pageTwo
            ->assertOk()
            ->assertSee('Charlie Pagina')
            ->assertDontSee('Alpha Pagina')
            ->assertViewHas('tenants', fn ($paginator): bool => $paginator->currentPage() === 2);
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
     * @param  array<string, mixed>  $attributes
     */
    private function createSnapshot(Tenant $tenant, array $attributes = []): LandlordTenantDetailSnapshot
    {
        return LandlordTenantDetailSnapshot::query()->create(array_replace([
            'tenant_id' => $tenant->id,
            'refresh_status' => 'ready',
            'last_refresh_source' => 'test',
            'payload_json' => [
                'provisioning' => ['code' => 'provisioned'],
            ],
            'generated_at' => now(),
            'last_refresh_started_at' => now(),
            'last_refresh_completed_at' => now(),
            'last_refresh_failed_at' => null,
            'last_refresh_error' => null,
        ], $attributes));
    }
}
