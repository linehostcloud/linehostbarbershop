<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileLandlordSnapshotBatchesAction
{
    /**
     * @return array{
     *     scanned:int,
     *     reconciled:int,
     *     batches:list<array{id:string, previous_status:string, new_status:string}>
     * }
     */
    public function execute(): array
    {
        $stuckAfterSeconds = max(60, (int) config(
            'landlord.tenants.detail_snapshot.batch_stuck_after_seconds',
            900,
        ));

        $stuckBatches = LandlordSnapshotBatchExecution::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($stuckAfterSeconds))
            ->orderBy('started_at')
            ->get();

        $result = [
            'scanned' => $stuckBatches->count(),
            'reconciled' => 0,
            'batches' => [],
        ];

        foreach ($stuckBatches as $batch) {
            $reconciled = $this->reconcileBatch($batch);

            if ($reconciled !== null) {
                $result['reconciled']++;
                $result['batches'][] = $reconciled;
            }
        }

        if ($result['reconciled'] > 0) {
            $this->logReconciliationSummary($result);
        }

        return $result;
    }

    /**
     * @return array{id:string, previous_status:string, new_status:string}|null
     */
    private function reconcileBatch(LandlordSnapshotBatchExecution $batch): ?array
    {
        if (! $batch->isRunning()) {
            return null;
        }

        $finalStatus = $this->resolveFinalStatus($batch);

        $updated = DB::connection('landlord')
            ->table('landlord_snapshot_batch_executions')
            ->where('id', $batch->getKey())
            ->where('status', 'running')
            ->update([
                'status' => $finalStatus,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return null;
        }

        $this->logBatchReconciled($batch, $finalStatus);

        return [
            'id' => (string) $batch->getKey(),
            'previous_status' => 'running',
            'new_status' => $finalStatus,
        ];
    }

    private function resolveFinalStatus(LandlordSnapshotBatchExecution $batch): string
    {
        $jobsReported = $batch->totalJobsReported();
        $hasUnreportedJobs = $jobsReported < $batch->total_queued;

        if ($batch->total_succeeded === 0 && $batch->total_failed === 0 && $jobsReported === 0) {
            return 'failed';
        }

        if ($batch->total_succeeded === 0 && $batch->total_failed > 0) {
            return 'failed';
        }

        if ($hasUnreportedJobs || $batch->total_failed > 0 || ($batch->total_skipped - $batch->dispatchSkippedCount()) > 0) {
            return 'partial';
        }

        return 'completed';
    }

    private function logBatchReconciled(LandlordSnapshotBatchExecution $batch, string $finalStatus): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::warning('Landlord snapshot batch reconciled (stuck).', [
            'event' => 'landlord.tenants.snapshots.batch_reconciled',
            'batch_id' => (string) $batch->getKey(),
            'type' => $batch->type,
            'previous_status' => 'running',
            'new_status' => $finalStatus,
            'total_queued' => $batch->total_queued,
            'total_succeeded' => $batch->total_succeeded,
            'total_failed' => $batch->total_failed,
            'total_skipped' => $batch->total_skipped,
            'jobs_reported' => $batch->totalJobsReported(),
            'unreported_jobs' => max(0, $batch->total_queued - $batch->totalJobsReported()),
            'started_at' => $batch->started_at?->toIso8601String(),
            'stuck_duration_seconds' => $batch->elapsedSeconds(),
        ]);
    }

    /**
     * @param  array{scanned:int, reconciled:int, batches:list<array{id:string, previous_status:string, new_status:string}>}  $result
     */
    private function logReconciliationSummary(array $result): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::info('Landlord snapshot batch reconciliation completed.', [
            'event' => 'landlord.tenants.snapshots.batch_reconciliation_completed',
            'scanned' => $result['scanned'],
            'reconciled' => $result['reconciled'],
        ]);
    }

    private function loggingEnabled(): bool
    {
        return (bool) config('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);
    }
}
