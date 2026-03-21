<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\ClassifyLandlordTenantSnapshotFailureAction;
use App\Application\Actions\Tenancy\ResolveLandlordTenantSnapshotRetryDelayAction;
use App\Jobs\RefreshLandlordTenantDetailSnapshotJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class LandlordSnapshotRetryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ClassifyLandlordTenantSnapshotFailureAction
    // -------------------------------------------------------------------------

    public function test_pdo_exception_is_classified_as_transient(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('SQLSTATE[HY000]: Connection refused'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('transient', $result['category']);
    }

    public function test_query_exception_is_classified_as_transient(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new QueryException('mysql', 'SELECT 1', [], new PDOException('server has gone away')));

        $this->assertTrue($result['retryable']);
        $this->assertSame('transient', $result['category']);
    }

    public function test_connection_refused_message_is_transient(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new RuntimeException('Connection refused to mariadb:3306'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('transient', $result['category']);
        $this->assertSame('transient_connection_error', $result['reason']);
    }

    public function test_deadlock_is_classified_as_transient_lock_contention(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('Deadlock found when trying to get lock'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('database_lock_contention', $result['reason']);
    }

    public function test_too_many_connections_is_classified_as_pool_exhausted(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('Too many connections'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('database_pool_exhausted', $result['reason']);
    }

    public function test_gone_away_is_classified_as_connection_lost(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('MySQL server has gone away'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('database_connection_lost', $result['reason']);
    }

    public function test_model_not_found_is_classified_as_persistent(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new ModelNotFoundException('Tenant not found'));

        $this->assertFalse($result['retryable']);
        $this->assertSame('persistent', $result['category']);
        $this->assertSame('model_not_found', $result['reason']);
    }

    public function test_invalid_argument_is_classified_as_persistent(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new InvalidArgumentException('Invalid tenant configuration'));

        $this->assertFalse($result['retryable']);
        $this->assertSame('persistent', $result['category']);
        $this->assertSame('invalid_argument', $result['reason']);
    }

    public function test_unknown_database_is_classified_as_persistent(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('SQLSTATE[HY000]: Unknown database \'tenant_xyz\''));

        $this->assertFalse($result['retryable']);
        $this->assertSame('persistent', $result['category']);
    }

    public function test_access_denied_is_classified_as_persistent(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new PDOException('Access denied for user'));

        $this->assertFalse($result['retryable']);
        $this->assertSame('persistent', $result['category']);
    }

    public function test_table_not_found_is_classified_as_persistent(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new RuntimeException("Base table or view not found: 1146 Table doesn't exist"));

        $this->assertFalse($result['retryable']);
        $this->assertSame('persistent', $result['category']);
    }

    public function test_unknown_exception_is_classified_as_retryable(): void
    {
        $action = new ClassifyLandlordTenantSnapshotFailureAction();

        $result = $action->execute(new RuntimeException('Something unexpected happened'));

        $this->assertTrue($result['retryable']);
        $this->assertSame('unknown', $result['category']);
        $this->assertSame('unclassified_exception', $result['reason']);
    }

    // -------------------------------------------------------------------------
    // ResolveLandlordTenantSnapshotRetryDelayAction
    // -------------------------------------------------------------------------

    public function test_first_attempt_is_eligible_with_60s_delay(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 1, retryable: true);

        $this->assertTrue($result['eligible']);
        $this->assertSame(60, $result['delay_seconds']);
        $this->assertSame(1, $result['attempt']);
        $this->assertNull($result['reason']);
    }

    public function test_second_attempt_is_eligible_with_300s_delay(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 2, retryable: true);

        $this->assertTrue($result['eligible']);
        $this->assertSame(300, $result['delay_seconds']);
    }

    public function test_third_attempt_is_eligible_with_900s_delay(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 3, retryable: true);

        $this->assertTrue($result['eligible']);
        $this->assertSame(900, $result['delay_seconds']);
    }

    public function test_fourth_attempt_is_not_eligible_max_reached(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 4, retryable: true);

        $this->assertFalse($result['eligible']);
        $this->assertNull($result['delay_seconds']);
        $this->assertSame('max_attempts_reached', $result['reason']);
    }

    public function test_persistent_failure_is_not_eligible(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 1, retryable: false);

        $this->assertFalse($result['eligible']);
        $this->assertSame('persistent_failure', $result['reason']);
    }

    public function test_custom_max_attempts_is_respected(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 2);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 2, retryable: true);

        $this->assertFalse($result['eligible']);
        $this->assertSame('max_attempts_reached', $result['reason']);
    }

    public function test_backoff_schedule_returns_configured_values(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [30, 120, 600]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $this->assertSame([30, 120, 600], $action->backoffSchedule());
    }

    public function test_backoff_uses_last_value_for_overflow(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 6);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300]);

        $action = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $result = $action->execute(currentAttempt: 5, retryable: true);

        $this->assertTrue($result['eligible']);
        $this->assertSame(300, $result['delay_seconds']);
    }

    // -------------------------------------------------------------------------
    // Job retry integration
    // -------------------------------------------------------------------------

    public function test_job_dispatches_retry_on_transient_failure(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-123',
            source: 'batch_filtered',
            batchId: 'batch-abc',
            attempt: 1,
        );

        $classification = ['retryable' => true, 'category' => 'transient', 'reason' => 'database_connection_error'];
        $retryDecision = ['eligible' => true, 'attempt' => 1, 'max_attempts' => 4, 'delay_seconds' => 60, 'reason' => null];

        $reflection = new \ReflectionMethod($job, 'scheduleRetry');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $retryDecision, $classification);

        Queue::assertPushed(RefreshLandlordTenantDetailSnapshotJob::class, function ($pushed) {
            return $pushed->tenantId === 'tenant-123'
                && $pushed->batchId === 'batch-abc'
                && $pushed->attempt === 2;
        });
    }

    public function test_job_reports_failed_only_on_final_attempt(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-123',
            source: 'batch_filtered',
            batchId: 'batch-abc',
            attempt: 1,
        );

        $reportBatch = $this->createMock(\App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction::class);
        $reportBatch->expects($this->never())->method('failed');

        $classifyFailure = new ClassifyLandlordTenantSnapshotFailureAction();
        $resolveRetry = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $reflection = new \ReflectionMethod($job, 'handleFailure');
        $reflection->setAccessible(true);
        $reflection->invoke($job, new PDOException('Connection refused'), $reportBatch, $classifyFailure, $resolveRetry);

        Queue::assertPushed(RefreshLandlordTenantDetailSnapshotJob::class);
    }

    public function test_job_reports_failed_when_retries_exhausted(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-123',
            source: 'batch_filtered',
            batchId: 'batch-abc',
            attempt: 4,
        );

        $reportBatch = $this->createMock(\App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction::class);
        $reportBatch->expects($this->once())->method('failed')->with('batch-abc');

        $classifyFailure = new ClassifyLandlordTenantSnapshotFailureAction();
        $resolveRetry = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $this->expectException(PDOException::class);

        $reflection = new \ReflectionMethod($job, 'handleFailure');
        $reflection->setAccessible(true);
        $reflection->invoke($job, new PDOException('Connection refused'), $reportBatch, $classifyFailure, $resolveRetry);
    }

    public function test_job_reports_failed_immediately_on_persistent_failure(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-123',
            source: 'batch_filtered',
            batchId: 'batch-abc',
            attempt: 1,
        );

        $reportBatch = $this->createMock(\App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction::class);
        $reportBatch->expects($this->once())->method('failed')->with('batch-abc');

        $classifyFailure = new ClassifyLandlordTenantSnapshotFailureAction();
        $resolveRetry = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $this->expectException(ModelNotFoundException::class);

        $reflection = new \ReflectionMethod($job, 'handleFailure');
        $reflection->setAccessible(true);
        $reflection->invoke($job, new ModelNotFoundException('Tenant not found'), $reportBatch, $classifyFailure, $resolveRetry);
    }

    public function test_job_does_not_report_to_batch_without_batch_id(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-123',
            source: 'manual',
            batchId: null,
            attempt: 4,
        );

        $reportBatch = $this->createMock(\App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction::class);
        $reportBatch->expects($this->never())->method('failed');

        $classifyFailure = new ClassifyLandlordTenantSnapshotFailureAction();
        $resolveRetry = new ResolveLandlordTenantSnapshotRetryDelayAction();

        $this->expectException(PDOException::class);

        $reflection = new \ReflectionMethod($job, 'handleFailure');
        $reflection->setAccessible(true);
        $reflection->invoke($job, new PDOException('Connection refused'), $reportBatch, $classifyFailure, $resolveRetry);
    }

    public function test_job_preserves_batch_id_and_source_across_retries(): void
    {
        Queue::fake();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-456',
            source: 'scheduled',
            batchId: 'batch-xyz',
            attempt: 2,
        );

        $classification = ['retryable' => true, 'category' => 'transient', 'reason' => 'database_connection_error'];
        $retryDecision = ['eligible' => true, 'attempt' => 2, 'max_attempts' => 4, 'delay_seconds' => 300, 'reason' => null];

        $reflection = new \ReflectionMethod($job, 'scheduleRetry');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $retryDecision, $classification);

        Queue::assertPushed(RefreshLandlordTenantDetailSnapshotJob::class, function ($pushed) {
            return $pushed->tenantId === 'tenant-456'
                && $pushed->source === 'scheduled'
                && $pushed->batchId === 'batch-xyz'
                && $pushed->attempt === 3;
        });
    }

    public function test_retry_logging_includes_all_context(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Log::spy();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);
        config()->set('observability.landlord_tenants_detail_snapshot.refresh_logging_enabled', true);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-789',
            source: 'batch_filtered',
            batchId: 'batch-log',
            attempt: 1,
        );

        $classification = ['retryable' => true, 'category' => 'transient', 'reason' => 'database_connection_error'];
        $retryDecision = ['eligible' => true, 'attempt' => 1, 'max_attempts' => 4, 'delay_seconds' => 60, 'reason' => null];

        $reflection = new \ReflectionMethod($job, 'scheduleRetry');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $retryDecision, $classification);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot refresh retry scheduled.'
                && data_get($context, 'event') === 'landlord.tenants.show.snapshot_retry_scheduled'
                && data_get($context, 'tenant_id') === 'tenant-789'
                && data_get($context, 'batch_id') === 'batch-log'
                && data_get($context, 'attempt') === 1
                && data_get($context, 'next_attempt') === 2
                && data_get($context, 'delay_seconds') === 60)
            ->once();
    }

    public function test_exhaust_logging_includes_exception_details(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\Log::spy();

        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('observability.landlord_tenants_detail_snapshot.refresh_logging_enabled', true);

        $job = new RefreshLandlordTenantDetailSnapshotJob(
            tenantId: 'tenant-exhaust',
            source: 'batch_filtered',
            batchId: null,
            attempt: 4,
        );

        $classification = ['retryable' => true, 'category' => 'transient', 'reason' => 'database_connection_error'];
        $retryDecision = ['eligible' => false, 'attempt' => 4, 'max_attempts' => 4, 'delay_seconds' => null, 'reason' => 'max_attempts_reached'];

        $exception = new PDOException('Connection lost forever');

        $reflection = new \ReflectionMethod($job, 'logRetryExhausted');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $classification, $retryDecision, $exception);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Landlord snapshot refresh retry exhausted.'
                && data_get($context, 'event') === 'landlord.tenants.show.snapshot_retry_exhausted'
                && data_get($context, 'attempt') === 4
                && data_get($context, 'exhaust_reason') === 'max_attempts_reached'
                && data_get($context, 'exception_class') === PDOException::class)
            ->once();
    }
}
