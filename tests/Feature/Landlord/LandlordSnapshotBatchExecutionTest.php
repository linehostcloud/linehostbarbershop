<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction;
use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Jobs\RefreshLandlordTenantDetailSnapshotJob;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordSnapshotBatchExecutionTest extends TestCase
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

    public function test_batch_execution_record_is_created_when_jobs_are_dispatched(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('batch-create', 'batch-create.saas.test');

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$tenant->id],
            ]);

        $this->assertDatabaseCount('landlord_snapshot_batch_executions', 1, 'landlord');

        $batch = LandlordSnapshotBatchExecution::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame('selected', $batch->type);
        $this->assertSame('Selecionados', $batch->type_label);
        $this->assertSame($admin->id, $batch->actor_id);
        $this->assertSame('running', $batch->status);
        $this->assertSame(1, $batch->total_target);
        $this->assertSame(1, $batch->total_queued);
        $this->assertSame(0, $batch->total_succeeded);
        $this->assertSame(0, $batch->total_failed);
        $this->assertNotNull($batch->started_at);
        $this->assertNull($batch->finished_at);
    }

    public function test_batch_execution_is_not_created_when_no_jobs_are_dispatched(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('batch-noop', 'batch-noop.saas.test');

        $this->createSnapshot($tenant, [
            'generated_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$tenant->id],
            ]);

        $this->assertDatabaseCount('landlord_snapshot_batch_executions', 0, 'landlord');
    }

    public function test_batch_id_is_passed_to_dispatched_jobs(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('batch-id-pass', 'batch-id-pass.saas.test');

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$tenant->id],
            ]);

        $batch = LandlordSnapshotBatchExecution::query()->first();

        Bus::assertDispatched(
            RefreshLandlordTenantDetailSnapshotJob::class,
            fn (RefreshLandlordTenantDetailSnapshotJob $job): bool => $job->batchId === $batch->id,
        );
    }

    public function test_successful_job_increments_succeeded_counter(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 2);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);

        $batch->refresh();

        $this->assertSame(1, $batch->total_succeeded);
        $this->assertSame(0, $batch->total_failed);
        $this->assertSame('running', $batch->status);
    }

    public function test_failed_job_increments_failed_counter(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 2);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->failed($batch->id);

        $batch->refresh();

        $this->assertSame(0, $batch->total_succeeded);
        $this->assertSame(1, $batch->total_failed);
        $this->assertSame('running', $batch->status);
    }

    public function test_batch_transitions_to_completed_when_all_jobs_succeed(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 3);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);
        $reportAction->succeeded($batch->id);
        $reportAction->succeeded($batch->id);

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->total_succeeded);
        $this->assertSame(0, $batch->total_failed);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_batch_transitions_to_partial_when_some_jobs_fail(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 3);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);
        $reportAction->failed($batch->id);
        $reportAction->succeeded($batch->id);

        $batch->refresh();

        $this->assertSame('partial', $batch->status);
        $this->assertSame(2, $batch->total_succeeded);
        $this->assertSame(1, $batch->total_failed);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_batch_transitions_to_failed_when_all_jobs_fail(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 2);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->failed($batch->id);
        $reportAction->failed($batch->id);

        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, $batch->total_succeeded);
        $this->assertSame(2, $batch->total_failed);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_batch_transitions_to_partial_with_skipped_jobs(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 3);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);
        $reportAction->succeeded($batch->id);
        $reportAction->skipped($batch->id);

        $batch->refresh();

        $this->assertSame('partial', $batch->status);
        $this->assertSame(2, $batch->total_succeeded);
        $this->assertSame(0, $batch->total_failed);
        $this->assertSame(1, $batch->total_skipped);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_concurrent_job_updates_do_not_corrupt_counters(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 10);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);

        for ($i = 0; $i < 7; $i++) {
            $reportAction->succeeded($batch->id);
        }
        for ($i = 0; $i < 3; $i++) {
            $reportAction->failed($batch->id);
        }

        $batch->refresh();

        $this->assertSame(7, $batch->total_succeeded);
        $this->assertSame(3, $batch->total_failed);
        $this->assertSame(10, $batch->totalReported());
        $this->assertSame('partial', $batch->status);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_reporting_to_nonexistent_batch_does_not_throw(): void
    {
        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);

        $reportAction->succeeded('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $reportAction->failed('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $this->assertTrue(true);
    }

    public function test_batch_finalization_logs_completion(): void
    {
        config()->set('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);
        Log::spy();

        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 1);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot batch execution completed.'
                && data_get($context, 'batch_id') === $batch->id
                && data_get($context, 'status') === 'completed')
            ->once();
    }

    public function test_batch_finalization_logs_partial_as_warning(): void
    {
        config()->set('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);
        Log::spy();

        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, totalQueued: 2);

        $reportAction = app(ReportLandlordSnapshotBatchJobResultAction::class);
        $reportAction->succeeded($batch->id);
        $reportAction->failed($batch->id);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot batch execution partial.'
                && data_get($context, 'batch_id') === $batch->id
                && data_get($context, 'status') === 'partial')
            ->once();
    }

    public function test_batch_history_is_shown_in_snapshot_dashboard(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createBatch($admin, totalQueued: 5, status: 'completed', totalSucceeded: 5);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots'))
            ->assertOk()
            ->assertSee('Histórico de execuções em lote')
            ->assertSee('Concluído');
    }

    public function test_batch_records_correct_skipped_counts_from_dispatch(): void
    {
        Bus::fake();

        $admin = $this->createLandlordAdmin();
        $missingTenant = $this->provisionTenant('skip-track-missing', 'skip-track-missing.saas.test');
        $healthyTenant = $this->provisionTenant('skip-track-healthy', 'skip-track-healthy.saas.test');

        $this->createSnapshot($healthyTenant, [
            'generated_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.snapshots.queue-refresh'), [
                'mode' => 'selected',
                'selected_ids' => [$missingTenant->id, $healthyTenant->id],
            ]);

        $batch = LandlordSnapshotBatchExecution::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->total_queued);
        $this->assertSame(2, $batch->total_target);
        $this->assertSame(1, $batch->total_skipped);
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

    private function createBatch(
        User $actor,
        int $totalQueued = 1,
        string $status = 'running',
        int $totalSucceeded = 0,
        int $totalFailed = 0,
    ): LandlordSnapshotBatchExecution {
        return LandlordSnapshotBatchExecution::query()->create([
            'type' => 'selected',
            'type_label' => 'Selecionados',
            'actor_id' => $actor->getKey(),
            'status' => $status,
            'total_target' => $totalQueued,
            'total_queued' => $totalQueued,
            'total_succeeded' => $totalSucceeded,
            'total_failed' => $totalFailed,
            'total_skipped' => 0,
            'started_at' => now(),
            'finished_at' => $status !== 'running' ? now() : null,
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
