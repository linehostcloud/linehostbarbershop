<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Observability\RecordLandlordTenantSnapshotBatchRefreshAction;
use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportLandlordSnapshotBatchJobResultAction
{
    public function __construct(
        private readonly RecordLandlordTenantSnapshotBatchRefreshAction $recordBatchRefresh,
    ) {}

    public function succeeded(string $batchId): void
    {
        $this->report($batchId, 'total_succeeded');
    }

    public function failed(string $batchId): void
    {
        $this->report($batchId, 'total_failed');
    }

    public function skipped(string $batchId): void
    {
        $this->report($batchId, 'total_skipped');
    }

    private function report(string $batchId, string $counterColumn): void
    {
        $affected = DB::connection('landlord')
            ->table('landlord_snapshot_batch_executions')
            ->where('id', $batchId)
            ->where('status', 'running')
            ->increment($counterColumn);

        if ($affected === 0) {
            return;
        }

        $this->tryFinalize($batchId);
    }

    private function tryFinalize(string $batchId): void
    {
        $batch = LandlordSnapshotBatchExecution::query()->find($batchId);

        if (! $batch instanceof LandlordSnapshotBatchExecution) {
            return;
        }

        if (! $batch->isRunning()) {
            return;
        }

        if ($batch->totalReported() < $batch->total_queued) {
            return;
        }

        $finalStatus = $this->resolveFinalStatus($batch);

        $updated = DB::connection('landlord')
            ->table('landlord_snapshot_batch_executions')
            ->where('id', $batchId)
            ->where('status', 'running')
            ->update([
                'status' => $finalStatus,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        $batch = $batch->fresh();

        if (! $batch instanceof LandlordSnapshotBatchExecution) {
            return;
        }

        $this->logFinalization($batch, $finalStatus);
    }

    private function resolveFinalStatus(LandlordSnapshotBatchExecution $batch): string
    {
        if ($batch->total_succeeded === 0 && $batch->total_failed > 0) {
            return 'failed';
        }

        if ($batch->total_failed > 0 || $batch->total_skipped > 0) {
            return 'partial';
        }

        return 'completed';
    }

    private function logFinalization(LandlordSnapshotBatchExecution $batch, string $finalStatus): void
    {
        if (! (bool) config('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true)) {
            return;
        }

        $context = [
            'event' => sprintf('landlord.tenants.snapshots.batch_execution_%s', $finalStatus),
            'batch_id' => (string) $batch->getKey(),
            'type' => $batch->type,
            'status' => $finalStatus,
            'total_queued' => $batch->total_queued,
            'total_succeeded' => $batch->total_succeeded,
            'total_failed' => $batch->total_failed,
            'total_skipped' => $batch->total_skipped,
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];

        if ($finalStatus === 'completed') {
            Log::info('Landlord snapshot batch execution completed.', $context);
        } else {
            Log::warning(sprintf('Landlord snapshot batch execution %s.', $finalStatus), $context);
        }
    }
}
