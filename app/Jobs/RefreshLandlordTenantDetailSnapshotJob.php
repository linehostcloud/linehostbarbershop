<?php

namespace App\Jobs;

use App\Application\Actions\Tenancy\RefreshLandlordTenantDetailSnapshotAction;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    ) {}

    public function handle(RefreshLandlordTenantDetailSnapshotAction $refreshSnapshot): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $refreshSnapshot->execute($tenant, $this->source);
    }
}
