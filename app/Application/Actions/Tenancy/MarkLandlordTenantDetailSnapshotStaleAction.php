<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;

class MarkLandlordTenantDetailSnapshotStaleAction
{
    public function execute(Tenant $tenant): void
    {
        /** @var LandlordTenantDetailSnapshot|null $snapshot */
        $snapshot = $tenant->relationLoaded('detailSnapshot')
            ? $tenant->detailSnapshot
            : $tenant->detailSnapshot()->first();

        if ($snapshot === null || ! is_array($snapshot->payload_json) || $snapshot->payload_json === []) {
            return;
        }

        if ($snapshot->refresh_status === 'refreshing') {
            return;
        }

        $snapshot->forceFill([
            'refresh_status' => 'stale',
        ])->save();
    }
}
