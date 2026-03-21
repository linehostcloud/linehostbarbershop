<?php

namespace App\Jobs;

use App\Application\Actions\Tenancy\RefreshLandlordTenantDetailSnapshotAction;
use App\Application\Actions\Tenancy\ReportLandlordSnapshotBatchJobResultAction;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    ) {}

    public function handle(
        RefreshLandlordTenantDetailSnapshotAction $refreshSnapshot,
        ReportLandlordSnapshotBatchJobResultAction $reportBatch,
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
            }
        } catch (Throwable $throwable) {
            $this->reportFailed($reportBatch);

            throw $throwable;
        }
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
