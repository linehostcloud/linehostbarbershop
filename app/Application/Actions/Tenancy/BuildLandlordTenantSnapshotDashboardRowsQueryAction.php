<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BuildLandlordTenantSnapshotDashboardRowsQueryAction
{
    public function __construct(
        private readonly DetermineLandlordTenantSnapshotPriorityAction $determinePriority,
    ) {}

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     */
    public function scopedRowsQuery(array $filters): Builder
    {
        $projectedRowsQuery = $this->projectedRowsQuery($filters);
        $highPriorityCutoff = now()
            ->subSeconds($this->determinePriority->highAgeThresholdSeconds())
            ->toDateTimeString();

        return DB::connection('landlord')
            ->query()
            ->fromSub($projectedRowsQuery, 'snapshot_dashboard_rows')
            ->select('*')
            ->selectRaw($this->priorityExpression().' as priority_rank', [$highPriorityCutoff, $highPriorityCutoff]);
    }

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     */
    public function filteredRowsQuery(array $filters): Builder
    {
        $query = $this->scopedRowsQuery($filters);

        $this->applySnapshotStatusFilter($query, $filters['snapshot_status']);

        return $query;
    }

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     */
    public function orderedFilteredRowsQuery(array $filters): Builder
    {
        $query = $this->filteredRowsQuery($filters);

        $this->applyOrdering($query, $filters['sort'], $filters['direction']);

        return $query;
    }

    public function applySnapshotStatusFilter(Builder $query, string $snapshotStatus): void
    {
        if ($snapshotStatus === '') {
            return;
        }

        if ($snapshotStatus === ResolveLandlordTenantSnapshotDashboardFiltersAction::SNAPSHOT_STATUS_FALLBACK) {
            $query->where('snapshot_has_payload', 0);

            return;
        }

        $resolvedStatus = match ($snapshotStatus) {
            ResolveLandlordTenantSnapshotDashboardFiltersAction::SNAPSHOT_STATUS_HEALTHY => 'ready',
            default => $snapshotStatus,
        };

        $query->where('snapshot_status_resolved', $resolvedStatus);
    }

    public function applyOrdering(Builder $query, string $sort, string $direction): void
    {
        if ($sort === ResolveLandlordTenantSnapshotDashboardFiltersAction::SORT_TENANT) {
            $query
                ->orderBy('trade_name', $direction)
                ->orderBy('slug', $direction);

            return;
        }

        if ($sort === ResolveLandlordTenantSnapshotDashboardFiltersAction::SORT_UPDATED_AT) {
            if ($direction === 'asc') {
                $query
                    ->orderByRaw('CASE WHEN snapshot_generated_at IS NULL THEN 0 ELSE 1 END ASC')
                    ->orderBy('snapshot_generated_at', 'asc');

                return;
            }

            $query
                ->orderByRaw('CASE WHEN snapshot_generated_at IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('snapshot_generated_at', 'desc');

            return;
        }

        if ($sort === ResolveLandlordTenantSnapshotDashboardFiltersAction::SORT_SNAPSHOT_AGE) {
            if ($direction === 'asc') {
                $query
                    ->orderByRaw('CASE WHEN snapshot_generated_at IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderBy('snapshot_generated_at', 'desc');

                return;
            }

            $query
                ->orderByRaw('CASE WHEN snapshot_generated_at IS NULL THEN 0 ELSE 1 END ASC')
                ->orderBy('snapshot_generated_at', 'asc');

            return;
        }

        $query
            ->orderBy('priority_rank', $direction)
            ->orderByRaw('CASE WHEN snapshot_generated_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('snapshot_generated_at', $direction === 'desc' ? 'asc' : 'desc')
            ->orderBy('trade_name', 'asc');
    }

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     */
    private function projectedRowsQuery(array $filters): Builder
    {
        $staleCutoff = now()
            ->subSeconds(max(60, (int) config('landlord.tenants.detail_snapshot.stale_after_seconds', 900)))
            ->toDateTimeString();

        return DB::connection('landlord')
            ->table('tenants')
            ->leftJoin('landlord_tenant_detail_snapshots as snapshots', 'snapshots.tenant_id', '=', 'tenants.id')
            ->select([
                'tenants.id',
                'tenants.trade_name',
                'tenants.slug',
                'tenants.status as tenant_status',
                'snapshots.refresh_status',
                'snapshots.generated_at as snapshot_generated_at',
                'snapshots.last_refresh_started_at',
                'snapshots.last_refresh_completed_at',
                'snapshots.last_refresh_failed_at',
                'snapshots.last_refresh_error',
            ])
            ->selectRaw('CASE WHEN snapshots.payload_json IS NULL THEN 0 ELSE 1 END as snapshot_has_payload')
            ->selectRaw($this->snapshotStatusExpression().' as snapshot_status_resolved', [$staleCutoff])
            ->when(
                $filters['tenant_status'] !== '',
                fn (Builder $query): Builder => $query->where('tenants.status', $filters['tenant_status']),
            )
            ->when(
                $filters['search'] !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $tenantQuery) use ($filters): void {
                    $search = '%'.$filters['search'].'%';

                    $tenantQuery
                        ->where('tenants.trade_name', 'like', $search)
                        ->orWhere('tenants.slug', 'like', $search);
                }),
            );
    }

    private function snapshotStatusExpression(): string
    {
        return <<<SQL
CASE
    WHEN snapshots.tenant_id IS NULL THEN 'missing'
    WHEN snapshots.refresh_status = 'refreshing' THEN 'refreshing'
    WHEN snapshots.refresh_status = 'failed' THEN 'failed'
    WHEN snapshots.refresh_status = 'stale' THEN 'stale'
    WHEN snapshots.payload_json IS NULL THEN 'missing'
    WHEN snapshots.generated_at IS NOT NULL AND snapshots.generated_at < ? THEN 'stale'
    ELSE 'ready'
END
SQL;
    }

    private function priorityExpression(): string
    {
        return <<<SQL
CASE
    WHEN snapshot_status_resolved = 'missing' THEN 300
    WHEN snapshot_status_resolved = 'failed' AND (snapshot_has_payload = 0 OR snapshot_generated_at IS NULL OR snapshot_generated_at < ?) THEN 300
    WHEN snapshot_status_resolved = 'stale' AND snapshot_generated_at IS NOT NULL AND snapshot_generated_at < ? THEN 300
    WHEN snapshot_status_resolved IN ('failed', 'stale', 'refreshing') THEN 200
    ELSE 100
END
SQL;
    }
}
