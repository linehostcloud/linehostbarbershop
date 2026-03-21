<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\ReconcileLandlordSnapshotBatchesAction;
use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordSnapshotBatchReconciliationTest extends TestCase
{
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
        config()->set('tenancy.provisioning.database_prefix', $this->testDatabaseDirectory.DIRECTORY_SEPARATOR.'tenant_');
        config()->set('tenancy.provisioning.default_domain_suffix', 'saas.test');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'saas.test');
        config()->set('landlord.tenants.detail_snapshot.batch_stuck_after_seconds', 900);
    }

    public function test_stuck_batch_is_detected_and_reconciled(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 3,
            'total_failed' => 0,
            'started_at' => now()->subMinutes(20),
        ]);

        $result = app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['reconciled']);
        $this->assertSame('partial', $result['batches'][0]['new_status']);

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_batch_not_stuck_within_threshold_is_not_reconciled(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 2,
            'started_at' => now()->subMinutes(5),
        ]);

        $result = app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, $result['reconciled']);
    }

    public function test_stuck_batch_with_no_reports_transitions_to_failed(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 3,
            'total_succeeded' => 0,
            'total_failed' => 0,
            'started_at' => now()->subMinutes(20),
        ]);

        app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
    }

    public function test_stuck_batch_with_all_failures_transitions_to_failed(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 3,
            'total_succeeded' => 0,
            'total_failed' => 2,
            'started_at' => now()->subMinutes(20),
        ]);

        app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
    }

    public function test_stuck_batch_with_all_jobs_reported_successfully_transitions_to_completed(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 3,
            'total_succeeded' => 3,
            'total_failed' => 0,
            'started_at' => now()->subMinutes(20),
        ]);

        app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
    }

    public function test_stuck_batch_with_mixed_results_transitions_to_partial(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 2,
            'total_failed' => 1,
            'started_at' => now()->subMinutes(20),
        ]);

        app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
    }

    public function test_already_finalized_batch_is_not_reconciled(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'status' => 'completed',
            'total_queued' => 3,
            'total_succeeded' => 3,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(18),
        ]);

        $result = app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, $result['reconciled']);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 3,
            'started_at' => now()->subMinutes(20),
        ]);

        $reconcile = app(ReconcileLandlordSnapshotBatchesAction::class);

        $result1 = $reconcile->execute();
        $this->assertSame(1, $result1['reconciled']);

        $result2 = $reconcile->execute();
        $this->assertSame(0, $result2['scanned']);
        $this->assertSame(0, $result2['reconciled']);

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
    }

    public function test_multiple_stuck_batches_are_reconciled_in_one_pass(): void
    {
        $admin = $this->createLandlordAdmin();

        $this->createBatch($admin, [
            'total_queued' => 3,
            'total_succeeded' => 3,
            'started_at' => now()->subMinutes(25),
        ]);
        $this->createBatch($admin, [
            'total_queued' => 2,
            'total_failed' => 2,
            'started_at' => now()->subMinutes(20),
        ]);

        $result = app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(2, $result['reconciled']);
        $this->assertSame('completed', $result['batches'][0]['new_status']);
        $this->assertSame('failed', $result['batches'][1]['new_status']);
    }

    public function test_reconciliation_logs_warning_for_each_stuck_batch(): void
    {
        config()->set('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);
        Log::spy();

        $admin = $this->createLandlordAdmin();
        $batch = $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 2,
            'started_at' => now()->subMinutes(20),
        ]);

        app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot batch reconciled (stuck).'
                && data_get($context, 'event') === 'landlord.tenants.snapshots.batch_reconciled'
                && data_get($context, 'batch_id') === $batch->id
                && data_get($context, 'new_status') === 'partial'
                && data_get($context, 'unreported_jobs') === 3)
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot batch reconciliation completed.'
                && data_get($context, 'reconciled') === 1)
            ->once();
    }

    public function test_reconciliation_respects_custom_stuck_threshold(): void
    {
        config()->set('landlord.tenants.detail_snapshot.batch_stuck_after_seconds', 60);

        $admin = $this->createLandlordAdmin();
        $this->createBatch($admin, [
            'total_queued' => 2,
            'total_succeeded' => 1,
            'started_at' => now()->subMinutes(2),
        ]);

        $result = app(ReconcileLandlordSnapshotBatchesAction::class)->execute();

        $this->assertSame(1, $result['reconciled']);
    }

    public function test_artisan_command_runs_reconciliation(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createBatch($admin, [
            'total_queued' => 3,
            'total_succeeded' => 1,
            'started_at' => now()->subMinutes(20),
        ]);

        $this->artisan('landlord:reconcile-snapshot-batches')
            ->assertExitCode(0)
            ->expectsOutputToContain('reconciliado');
    }

    public function test_progress_percentage_is_calculated_correctly(): void
    {
        $admin = $this->createLandlordAdmin();

        $batch = $this->createBatch($admin, [
            'total_queued' => 10,
            'total_succeeded' => 7,
            'total_failed' => 3,
        ]);

        $this->assertSame(100, $batch->progressPercentage());
    }

    public function test_progress_percentage_with_partial_completion(): void
    {
        $admin = $this->createLandlordAdmin();

        $batch = $this->createBatch($admin, [
            'total_queued' => 10,
            'total_succeeded' => 3,
            'total_failed' => 2,
        ]);

        $this->assertSame(50, $batch->progressPercentage());
    }

    public function test_is_stuck_returns_true_beyond_threshold(): void
    {
        $admin = $this->createLandlordAdmin();

        $batch = $this->createBatch($admin, [
            'started_at' => now()->subMinutes(20),
        ]);

        $this->assertTrue($batch->isStuck(900));
    }

    public function test_is_stuck_returns_false_within_threshold(): void
    {
        $admin = $this->createLandlordAdmin();

        $batch = $this->createBatch($admin, [
            'started_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse($batch->isStuck(900));
    }

    public function test_is_stuck_returns_false_for_finalized_batch(): void
    {
        $admin = $this->createLandlordAdmin();

        $batch = $this->createBatch($admin, [
            'status' => 'completed',
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(18),
        ]);

        $this->assertFalse($batch->isStuck(900));
    }

    public function test_dispatch_skipped_count_is_stored_in_metadata(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = LandlordSnapshotBatchExecution::query()->create([
            'type' => 'selected',
            'type_label' => 'Selecionados',
            'actor_id' => $admin->getKey(),
            'status' => 'running',
            'total_target' => 5,
            'total_queued' => 3,
            'total_succeeded' => 0,
            'total_failed' => 0,
            'total_skipped' => 2,
            'metadata_json' => [
                'dispatch_skipped_count' => 2,
            ],
            'started_at' => now(),
        ]);

        $this->assertSame(2, $batch->dispatchSkippedCount());
        $this->assertSame(0, $batch->totalJobsReported());
    }

    public function test_total_jobs_reported_excludes_dispatch_skips(): void
    {
        $admin = $this->createLandlordAdmin();
        $batch = LandlordSnapshotBatchExecution::query()->create([
            'type' => 'selected',
            'type_label' => 'Selecionados',
            'actor_id' => $admin->getKey(),
            'status' => 'running',
            'total_target' => 8,
            'total_queued' => 5,
            'total_succeeded' => 2,
            'total_failed' => 1,
            'total_skipped' => 4,
            'metadata_json' => [
                'dispatch_skipped_count' => 3,
            ],
            'started_at' => now(),
        ]);

        $this->assertSame(4, $batch->totalJobsReported());
        $this->assertSame(80, $batch->progressPercentage());
    }

    public function test_stuck_batch_shown_in_dashboard(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createBatch($admin, [
            'total_queued' => 5,
            'total_succeeded' => 2,
            'started_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.snapshots'))
            ->assertOk()
            ->assertSee('Stuck')
            ->assertSee('40%');
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
     * @param  array<string, mixed>  $overrides
     */
    private function createBatch(User $actor, array $overrides = []): LandlordSnapshotBatchExecution
    {
        return LandlordSnapshotBatchExecution::query()->create(array_merge([
            'type' => 'selected',
            'type_label' => 'Selecionados',
            'actor_id' => $actor->getKey(),
            'status' => 'running',
            'total_target' => 5,
            'total_queued' => 5,
            'total_succeeded' => 0,
            'total_failed' => 0,
            'total_skipped' => 0,
            'metadata_json' => [
                'dispatch_skipped_count' => 0,
            ],
            'started_at' => now(),
            'finished_at' => null,
        ], $overrides));
    }
}
