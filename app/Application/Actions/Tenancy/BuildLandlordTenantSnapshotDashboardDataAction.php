<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildLandlordTenantSnapshotDashboardDataAction
{
    public function __construct(
        private readonly BuildLandlordTenantSnapshotDashboardRowsQueryAction $buildRowsQuery,
        private readonly ResolveLandlordTenantDetailSnapshotStateAction $resolveSnapshotState,
        private readonly ResolveLandlordTenantSnapshotDashboardFiltersAction $resolveFilters,
        private readonly DetermineLandlordTenantSnapshotPriorityAction $determinePriority,
        private readonly ResolveLandlordTenantSnapshotRetryStateAction $resolveRetryState,
    ) {}

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     * @return array{
     *     headline:array<string, int>,
     *     tenants:LengthAwarePaginator
     * }
     */
    public function execute(array $filters): array
    {
        $scopedRowsQuery = $this->buildRowsQuery->scopedRowsQuery($filters);

        return [
            'headline' => $this->buildHeadline((clone $scopedRowsQuery)),
            'tenants' => $this->buildPaginator($filters),
        ];
    }

    private function buildHeadline(Builder $projectedRowsQuery): array
    {
        $summary = DB::connection('landlord')
            ->query()
            ->fromSub($projectedRowsQuery, 'snapshot_dashboard_rows')
            ->selectRaw('COUNT(*) as total_monitored')
            ->selectRaw("SUM(CASE WHEN snapshot_status_resolved = 'ready' THEN 1 ELSE 0 END) as healthy_count")
            ->selectRaw("SUM(CASE WHEN snapshot_status_resolved = 'stale' THEN 1 ELSE 0 END) as stale_count")
            ->selectRaw("SUM(CASE WHEN snapshot_status_resolved = 'missing' THEN 1 ELSE 0 END) as missing_count")
            ->selectRaw("SUM(CASE WHEN snapshot_status_resolved = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN snapshot_status_resolved = 'refreshing' THEN 1 ELSE 0 END) as refreshing_count")
            ->selectRaw('SUM(CASE WHEN snapshot_has_payload = 0 THEN 1 ELSE 0 END) as fallback_count')
            ->first();

        return [
            'total_monitored' => (int) ($summary->total_monitored ?? 0),
            'healthy_count' => (int) ($summary->healthy_count ?? 0),
            'stale_count' => (int) ($summary->stale_count ?? 0),
            'missing_count' => (int) ($summary->missing_count ?? 0),
            'failed_count' => (int) ($summary->failed_count ?? 0),
            'refreshing_count' => (int) ($summary->refreshing_count ?? 0),
            'fallback_count' => (int) ($summary->fallback_count ?? 0),
        ];
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
    private function buildPaginator(array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) request()->query('page', 1));
        $perPage = (int) config('landlord.tenants.list_per_page', 15);
        $query = $this->buildRowsQuery->orderedFilteredRowsQuery($filters);

        $rows = (clone $query)
            ->forPage($page, $perPage)
            ->get();
        $total = (clone $query)->count();
        $mappedRows = $this->mapRows($rows);

        return (new LaravelLengthAwarePaginator(
            items: $mappedRows,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'pageName' => 'page',
            ],
        ))->withQueryString();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function mapRows(Collection $rows): Collection
    {
        $tenantStatusLabels = $this->resolveFilters->options()['tenant_status'];

        return $rows
            ->map(function (object $row) use ($tenantStatusLabels): array {
                $snapshotState = $this->resolveSnapshotState->execute(
                    refreshStatus: $row->refresh_status !== null ? (string) $row->refresh_status : null,
                    hasPayload: (bool) $row->snapshot_has_payload,
                    generatedAt: $row->snapshot_generated_at,
                    lastRefreshStartedAt: $row->last_refresh_started_at,
                    lastRefreshCompletedAt: $row->last_refresh_completed_at,
                    lastRefreshFailedAt: $row->last_refresh_failed_at,
                    lastRefreshError: $row->last_refresh_error !== null ? (string) $row->last_refresh_error : null,
                );
                $priority = $this->determinePriority->execute(
                    snapshotStatus: $snapshotState['status'],
                    hasPayload: (bool) $row->snapshot_has_payload,
                    generatedAt: $row->snapshot_generated_at,
                );

                $retryState = $this->resolveRetryState->execute(
                    retryAttempt: (int) ($row->retry_attempt ?? 0),
                    nextRetryAt: $row->next_retry_at,
                    retryExhaustedAt: $row->retry_exhausted_at,
                    lastRefreshError: $row->last_refresh_error !== null ? (string) $row->last_refresh_error : null,
                );

                return [
                    'id' => (string) $row->id,
                    'tenant' => [
                        'trade_name' => (string) $row->trade_name,
                        'slug' => (string) $row->slug,
                    ],
                    'status' => [
                        'code' => (string) $row->tenant_status,
                        'label' => $tenantStatusLabels[(string) $row->tenant_status] ?? ucfirst((string) $row->tenant_status),
                    ],
                    'snapshot_status' => [
                        'code' => $snapshotState['status'],
                        'label' => $this->snapshotStatusListLabel($snapshotState['status']),
                        'detail' => $snapshotState['detail'],
                        'tone' => $this->snapshotTone($snapshotState['status']),
                    ],
                    'snapshot_generated_at' => $snapshotState['generated_at'],
                    'snapshot_generated_at_iso' => $snapshotState['generated_at_iso'],
                    'snapshot_age_seconds' => $snapshotState['age_seconds'],
                    'snapshot_age_label' => $this->ageLabel($snapshotState['age_seconds']),
                    'snapshot_is_stale' => $snapshotState['is_stale'],
                    'last_failure' => [
                        'at' => $snapshotState['last_refresh_failed_at'],
                        'error' => $snapshotState['last_refresh_error'],
                    ],
                    'refresh_in_progress' => $snapshotState['status'] === 'refreshing',
                    'refresh_started_at' => $snapshotState['last_refresh_started_at'],
                    'fallback_conservative' => ! (bool) $row->snapshot_has_payload,
                    'priority' => $priority,
                    'retry' => $retryState,
                ];
            })
            ->values();
    }

    private function snapshotStatusListLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Healthy',
            'stale' => 'Stale',
            'failed' => 'Failed',
            'refreshing' => 'Refreshing',
            default => 'Missing',
        };
    }

    private function snapshotTone(string $status): string
    {
        return match ($status) {
            'ready' => 'emerald',
            'refreshing' => 'sky',
            'stale' => 'amber',
            default => 'rose',
        };
    }

    private function ageLabel(?int $ageSeconds): ?string
    {
        if ($ageSeconds === null) {
            return null;
        }

        return Carbon::now()
            ->subSeconds(max(0, $ageSeconds))
            ->locale('pt_BR')
            ->diffForHumans();
    }
}
