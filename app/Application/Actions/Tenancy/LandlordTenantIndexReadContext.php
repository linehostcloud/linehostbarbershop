<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Support\Collection;

class LandlordTenantIndexReadContext
{
    /**
     * @param  Collection<int, array<string, mixed>>  $tenantSummaries
     * @param  Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     total_blocks:int,
     *     affected_channels_count:int,
     *     last_blocked_at:string|null,
     *     channels:list<string>
     * }>  $suspendedPressure
     */
    public function __construct(
        public readonly Collection $tenantSummaries,
        public readonly Collection $suspendedPressure,
    ) {
    }
}
