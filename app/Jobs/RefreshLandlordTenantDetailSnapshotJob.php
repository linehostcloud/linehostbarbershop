<?php

namespace App\Jobs;

use App\Application\Actions\Tenancy\ClassifyLandlordTenantSnapshotFailureAction;
use App\Application\Actions\Tenancy\RefreshLandlordTenantDetailSnapshotAction;
use App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction;
use App\Application\Actions\Tenancy\ResolveLandlordTenantSnapshotRetryDelayAction;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshLandlordTenantDetailSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $source = 'batch_filtered',
        public readonly ?string $batchId = null,
        public readonly int $attempt = 1,
    ) {}

    public function handle(
        RefreshLandlordTenantDetailSnapshotAction $refreshSnapshot,
        ReportLandlordSnapshotBatchJobResultAction $reportBatch,
        ClassifyLandlordTenantSnapshotFailureAction $classifyFailure,
        ResolveLandlordTenantSnapshotRetryDelayAction $resolveRetry,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            $this->reportSkipped($reportBatch);

            return;
        }

        try {
            $result = $refreshSnapshot->execute($tenant, $this->source);

            if ($result['status'] === 'skipped_locked') {
                $this->reportSkipped($reportBatch);
            } else {
                $this->reportSucceeded($reportBatch);
                $this->clearRetryState();
            }
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable, $reportBatch, $classifyFailure, $resolveRetry);
        }
    }

    private function handleFailure(
        Throwable $throwable,
        ReportLandlordSnapshotBatchJobResultAction $reportBatch,
        ClassifyLandlordTenantSnapshotFailureAction $classifyFailure,
        ResolveLandlordTenantSnapshotRetryDelayAction $resolveRetry,
    ): void {
        $classification = $classifyFailure->execute($throwable);
        $retryDecision = $resolveRetry->execute($this->attempt, $classification['retryable']);

        if ($retryDecision['eligible']) {
            $this->scheduleRetry($retryDecision, $classification);

            return;
        }

        $this->persistRetryExhausted();
        $this->reportFailed($reportBatch);
        $this->logRetryExhausted($classification, $retryDecision, $throwable);

        throw $throwable;
    }

    private function scheduleRetry(array $retryDecision, array $classification): void
    {
        $nextAttempt = $this->attempt + 1;
        $nextRetryAt = now()->addSeconds($retryDecision['delay_seconds']);

        self::dispatch(
            tenantId: $this->tenantId,
            source: $this->source,
            batchId: $this->batchId,
            attempt: $nextAttempt,
        )->delay($nextRetryAt);

        $this->persistRetryScheduled($this->attempt, $nextRetryAt);
        $this->logRetryScheduled($retryDecision, $classification);
    }

    private function persistRetryScheduled(int $attempt, \DateTimeInterface $nextRetryAt): void
    {
        LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $this->tenantId)
            ->update([
                'retry_attempt' => $attempt,
                'next_retry_at' => $nextRetryAt,
                'retry_exhausted_at' => null,
            ]);
    }

    private function persistRetryExhausted(): void
    {
        LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $this->tenantId)
            ->update([
                'retry_attempt' => $this->attempt,
                'next_retry_at' => null,
                'retry_exhausted_at' => now(),
            ]);
    }

    private function clearRetryState(): void
    {
        LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $this->tenantId)
            ->where(function ($query) {
                $query->where('retry_attempt', '>', 0)
                    ->orWhereNotNull('next_retry_at')
                    ->orWhereNotNull('retry_exhausted_at');
            })
            ->update([
                'retry_attempt' => 0,
                'next_retry_at' => null,
                'retry_exhausted_at' => null,
            ]);
    }

    private function logRetryScheduled(array $retryDecision, array $classification): void
    {
        if (! $this->retryLoggingEnabled()) {
            return;
        }

        Log::info('Landlord snapshot refresh retry scheduled.', [
            'event' => 'landlord.tenants.show.snapshot_retry_scheduled',
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'attempt' => $this->attempt,
            'next_attempt' => $this->attempt + 1,
            'max_attempts' => $retryDecision['max_attempts'],
            'delay_seconds' => $retryDecision['delay_seconds'],
            'failure_category' => $classification['category'],
            'failure_reason' => $classification['reason'],
        ]);
    }

    private function logRetryExhausted(array $classification, array $retryDecision, Throwable $throwable): void
    {
        if (! $this->retryLoggingEnabled()) {
            return;
        }

        Log::warning('Landlord snapshot refresh retry exhausted.', [
            'event' => 'landlord.tenants.show.snapshot_retry_exhausted',
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'attempt' => $this->attempt,
            'max_attempts' => $retryDecision['max_attempts'],
            'failure_category' => $classification['category'],
            'failure_reason' => $classification['reason'],
            'exhaust_reason' => $retryDecision['reason'],
            'exception_class' => $throwable::class,
            'exception_message' => $throwable->getMessage(),
        ]);
    }

    private function retryLoggingEnabled(): bool
    {
        return (bool) config('observability.landlord_tenants_detail_snapshot.refresh_logging_enabled', true);
    }

    private function reportSucceeded(ReportLandlordSnapshotBatchJobResultAction $reportBatch): void
    {
        if ($this->batchId !== null) {
            $reportBatch->succeeded($this->batchId);
        }
    }

    private function reportFailed(ReportLandlordSnapshotBatchJobResultAction $reportBatch): void
    {
        if ($this->batchId !== null) {
            $reportBatch->failed($this->batchId);
        }
    }

    private function reportSkipped(ReportLandlordSnapshotBatchJobResultAction $reportBatch): void
    {
        if ($this->batchId !== null) {
            $reportBatch->skipped($this->batchId);
        }
    }
}
