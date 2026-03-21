<?php

namespace Tests\Feature\Landlord;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use App\Jobs\RefreshLandlordTenantDetailSnapshotJob;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantSnapshotBatchRefreshTest extends TestCase
{
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
        config()->set('tenancy.provisioning.database_prefix', $this->testDatabaseDirectory.DIRECTORY_SEPARATOR.'tenant_');
        config()->set('tenancy.provisioning.default_domain_suffix', 'saas.test');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'saas.test');
        config()->set('landlord.tenants.detail_snapshot.batch_refresh_cooldown_seconds', 120);
    }

    public function test_landlord_can_queue_batch_refresh_for_selected_tenants(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $missingTenant = $this->provisionTenant('lote-missing', 'lote-missing.saas.test');
        $staleTenant = $this->provisionTenant('lote-stale', 'lote-stale.saas.test');
        $healthyTenant = $this->provisionTenant('lote-healthy', 'lote-healthy.saas.test');

        $this->createSnapshot($staleTenant, [
            'generated_at' => now()->subHours(2),
        ]);
        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($admin)
            ->from(route('landlord.tenants.snapshots'))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$missingTenant->id, $staleTenant->id, $healthyTenant->id],
            ])
            ->assertRedirect(route('landlord.tenants.snapshots'))
            ->assertSessionHas('status.type', 'warning')
            ->assertSessionHas('status.summary.dispatched_count', 2)
            ->assertSessionHas('status.summary.skipped_healthy_count', 1)
            ->assertSessionHas('status.summary.skipped_locked_count', 0);

        Bus::assertDispatchedTimes(RefreshLandlordTenantDetailSnapshotJob::class, 2);
        Bus::assertDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => $job->tenantId === $missingTenant->id && $job->source === 'batch_selected');
        Bus::assertDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => $job->tenantId === $staleTenant->id && $job->source === 'batch_selected');

        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'landlord_tenant.detail_snapshot_batch_refresh_queued')->count(),
        );
    }

    public function test_landlord_can_queue_batch_refresh_for_the_current_filtered_query(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $failedTenant = $this->provisionTenant('filtro-failed', 'filtro-failed.saas.test');
        $staleTenant = $this->provisionTenant('filtro-stale', 'filtro-stale.saas.test');

        $this->createSnapshot($failedTenant, [
            'refresh_status' => 'failed',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_failed_at' => now()->subMinutes(10),
            'last_refresh_error' => 'Falha relevante.',
        ]);
        $this->createSnapshot($staleTenant, [
            'generated_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin)
            ->from(route('landlord.tenants.snapshots', ['snapshot_status' => 'failed']))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'filtered',
                'snapshot_status' => 'failed',
            ])
            ->assertRedirect(route('landlord.tenants.snapshots', ['snapshot_status' => 'failed']))
            ->assertSessionHas('status.summary.dispatched_count', 1)
            ->assertSessionHas('status.summary.matched_count', 1);

        Bus::assertDispatchedTimes(RefreshLandlordTenantDetailSnapshotJob::class, 1);
        Bus::assertDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => $job->tenantId === $failedTenant->id && $job->source === 'batch_filtered');
    }

    public function test_landlord_can_queue_batch_refresh_for_critical_tenants_only(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $missingTenant = $this->provisionTenant('critico-missing', 'critico-missing.saas.test');
        $staleTenant = $this->provisionTenant('critico-stale', 'critico-stale.saas.test');
        $refreshingTenant = $this->provisionTenant('critico-refreshing', 'critico-refreshing.saas.test');
        $healthyTenant = $this->provisionTenant('critico-healthy', 'critico-healthy.saas.test');

        $this->createSnapshot($staleTenant, [
            'generated_at' => now()->subHours(3),
        ]);
        $this->createSnapshot($refreshingTenant, [
            'refresh_status' => 'refreshing',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_started_at' => now()->subMinutes(5),
        ]);
        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($admin)
            ->from(route('landlord.tenants.snapshots'))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'critical',
            ])
            ->assertRedirect(route('landlord.tenants.snapshots'))
            ->assertSessionHas('status.summary.dispatched_count', 2)
            ->assertSessionHas('status.summary.matched_count', 2);

        Bus::assertDispatchedTimes(RefreshLandlordTenantDetailSnapshotJob::class, 2);
        Bus::assertDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => in_array($job->tenantId, [$missingTenant->id, $staleTenant->id], true));
        Bus::assertNotDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => in_array($job->tenantId, [$refreshingTenant->id, $healthyTenant->id], true));
    }

    public function test_landlord_batch_refresh_reports_locked_refreshing_healthy_and_cooldown_skips(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $lockedTenant = $this->provisionTenant('skip-locked', 'skip-locked.saas.test');
        $refreshingTenant = $this->provisionTenant('skip-refreshing', 'skip-refreshing.saas.test');
        $healthyTenant = $this->provisionTenant('skip-healthy', 'skip-healthy.saas.test');
        $cooldownTenant = $this->provisionTenant('skip-cooldown', 'skip-cooldown.saas.test');
        $dispatchTenant = $this->provisionTenant('skip-dispatch', 'skip-dispatch.saas.test');

        $this->createSnapshot($refreshingTenant, [
            'refresh_status' => 'refreshing',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_started_at' => now()->subMinutes(3),
        ]);
        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(1),
        ]);
        $this->createSnapshot($cooldownTenant, [
            'refresh_status' => 'failed',
            'payload_json' => null,
            'generated_at' => null,
            'last_refresh_failed_at' => now()->subSeconds(30),
            'last_refresh_error' => 'Falha recente em cooldown.',
        ]);
        $this->createSnapshot($dispatchTenant, [
            'generated_at' => now()->subHours(2),
        ]);

        $lockKey = app(TenantExecutionLockManager::class)
            ->lockKeyForTenant($lockedTenant, 'landlord_tenant_detail_snapshot_refresh');
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->from(route('landlord.tenants.snapshots'))
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                    'mode' => 'selected',
                    'selected_ids' => [
                        $lockedTenant->id,
                        $refreshingTenant->id,
                        $healthyTenant->id,
                        $cooldownTenant->id,
                        $dispatchTenant->id,
                    ],
                ])
                ->assertRedirect(route('landlord.tenants.snapshots'))
                ->assertSessionHas('status.summary.dispatched_count', 1)
                ->assertSessionHas('status.summary.skipped_locked_count', 1)
                ->assertSessionHas('status.summary.skipped_refreshing_count', 1)
                ->assertSessionHas('status.summary.skipped_healthy_count', 1)
                ->assertSessionHas('status.summary.skipped_cooldown_count', 1);
        } finally {
            $lock->release();
        }

        Bus::assertDispatchedTimes(RefreshLandlordTenantDetailSnapshotJob::class, 1);
        Bus::assertDispatched(RefreshLandlordTenantDetailSnapshotJob::class, fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => $job->tenantId === $dispatchTenant->id);
    }

    public function test_landlord_batch_refresh_requires_landlord_admin(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'name' => 'Operador comum',
            'email' => 'operador@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'filtered',
            ])
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_landlord_batch_refresh_records_structured_logs(): void
    {
        config()->set('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);

        Bus::fake();
        Log::spy();

        $admin = $this->createLandlordAdmin();
        $missingTenant = $this->provisionTenant('log-missing', 'log-missing.saas.test');
        $healthyTenant = $this->provisionTenant('log-healthy', 'log-healthy.saas.test');

        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($admin)
            ->from(route('landlord.tenants.snapshots'))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$missingTenant->id, $healthyTenant->id],
            ])
            ->assertRedirect(route('landlord.tenants.snapshots'));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($admin): bool {
                return $message === 'Landlord tenant snapshot batch refresh started.'
                    && data_get($context, 'event') === 'landlord.tenants.snapshots.batch_refresh_started'
                    && data_get($context, 'actor_user_id') === $admin->id
                    && data_get($context, 'mode') === 'selected';
            })
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($admin): bool {
                return $message === 'Landlord tenant snapshot batch refresh partially completed.'
                    && data_get($context, 'event') === 'landlord.tenants.snapshots.batch_refresh_partially_completed'
                    && data_get($context, 'actor_user_id') === $admin->id
                    && data_get($context, 'dispatched_count') === 1
                    && data_get($context, 'skipped_healthy_count') === 1;
            })
            ->once();
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
        $generatedAt = array_key_exists('generated_at', $attributes)
            ? $attributes['generated_at']
            : now();
        $lastRefreshCompletedAt = array_key_exists('last_refresh_completed_at', $attributes)
            ? $attributes['last_refresh_completed_at']
            : $generatedAt;

        return LandlordTenantDetailSnapshot::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'refresh_status' => 'ready',
            'last_refresh_source' => 'test',
            'payload_json' => [
                'provisioning' => [
                    'code' => 'provisioned',
                ],
            ],
            'generated_at' => $generatedAt,
            'last_refresh_started_at' => null,
            'last_refresh_completed_at' => $lastRefreshCompletedAt,
            'last_refresh_failed_at' => null,
            'last_refresh_error' => null,
        ], $attributes));
    }
}
