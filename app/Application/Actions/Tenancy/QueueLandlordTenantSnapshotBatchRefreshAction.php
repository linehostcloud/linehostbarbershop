<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Application\Actions\Observability\RecordLandlordTenantSnapshotBatchRefreshAction;
use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use App\Jobs\RefreshLandlordTenantDetailSnapshotJob;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class QueueLandlordTenantSnapshotBatchRefreshAction
{
    public const MODE_SELECTED = 'selected';

    public const MODE_FILTERED = 'filtered';

    public const MODE_CRITICAL = 'critical';

    public const MODE_MISSING = 'missing';

    public const MODE_STALE = 'stale';

    public const MODE_FAILED = 'failed';

    private const LOCK_OPERATION = RefreshLandlordTenantDetailSnapshotAction::LOCK_OPERATION;

    public function __construct(
        private readonly BuildLandlordTenantSnapshotDashboardRowsQueryAction $buildRowsQuery,
        private readonly DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction $determineEligibility,
        private readonly TenantExecutionLockManager $lockManager,
        private readonly RecordAuditLogAction $recordAuditLog,
        private readonly RecordLandlordTenantSnapshotBatchRefreshAction $recordBatchRefresh,
    ) {}

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     * @param  list<string>  $selectedTenantIds
     * @return array{
     *     result_status:string,
     *     batch_id:string,
     *     mode:string,
     *     mode_label:string,
     *     filters:array<string, string>,
     *     selected_count:int,
     *     matched_count:int,
     *     eligible_count:int,
     *     dispatched_count:int,
     *     skipped_locked_count:int,
     *     skipped_refreshing_count:int,
     *     skipped_healthy_count:int,
     *     skipped_cooldown_count:int,
     *     dispatch_failed_count:int,
     *     duplicate_submission:bool
     * }
     */
    public function execute(User $actor, string $mode, array $filters, array $selectedTenantIds = []): array
    {
        $batchId = (string) Str::ulid();
        $selectedTenantIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $selectedTenantIds,
        ))));
        $submissionLock = Cache::lock(
            $this->submissionLockKey($actor, $mode, $filters, $selectedTenantIds),
            max(1, (int) config('landlord.tenants.detail_snapshot.batch_submission_lock_seconds', 15)),
        );

        if (! $submissionLock->get()) {
            return [
                'result_status' => 'duplicate_submission',
                'batch_id' => $batchId,
                'mode' => $mode,
                'mode_label' => $this->modeLabel($mode),
                'filters' => $filters,
                'selected_count' => count($selectedTenantIds),
                'matched_count' => 0,
                'eligible_count' => 0,
                'dispatched_count' => 0,
                'skipped_locked_count' => 0,
                'skipped_refreshing_count' => 0,
                'skipped_healthy_count' => 0,
                'skipped_cooldown_count' => 0,
                'dispatch_failed_count' => 0,
                'duplicate_submission' => true,
            ];
        }

        $summary = [
            'result_status' => 'completed',
            'batch_id' => $batchId,
            'mode' => $mode,
            'mode_label' => $this->modeLabel($mode),
            'filters' => $filters,
            'selected_count' => count($selectedTenantIds),
            'matched_count' => 0,
            'eligible_count' => 0,
            'dispatched_count' => 0,
            'skipped_locked_count' => 0,
            'skipped_refreshing_count' => 0,
            'skipped_healthy_count' => 0,
            'skipped_cooldown_count' => 0,
            'dispatch_failed_count' => 0,
            'duplicate_submission' => false,
        ];

        $context = [
            'batch_id' => $batchId,
            'mode' => $mode,
            'mode_label' => $summary['mode_label'],
            'selected_count' => $summary['selected_count'],
            'filters' => $this->filterContext($filters),
        ];

        $this->recordBatchRefresh->started($actor, $context);

        try {
            $rows = $this->targetRowsQuery($mode, $filters, $selectedTenantIds)
                ->orderByDesc('priority_rank')
                ->orderBy('trade_name')
                ->get();
            $summary['matched_count'] = $rows->count();

            /** @var Collection<string, Tenant> $tenantsById */
            $tenantsById = Tenant::query()
                ->whereIn('id', $rows->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())
                ->get()
                ->keyBy(fn (Tenant $tenant): string => (string) $tenant->getKey());

            foreach ($rows as $row) {
                $eligibility = $this->determineEligibility->execute(
                    snapshotStatus: (string) $row->snapshot_status_resolved,
                    lastRefreshStartedAt: $row->last_refresh_started_at,
                    lastRefreshCompletedAt: $row->last_refresh_completed_at,
                    lastRefreshFailedAt: $row->last_refresh_failed_at,
                );

                if (! $eligibility['dispatchable']) {
                    $this->incrementSkippedSummary($summary, (string) $eligibility['reason']);

                    continue;
                }

                $tenant = $tenantsById->get((string) $row->id);

                if (! $tenant instanceof Tenant) {
                    $summary['dispatch_failed_count']++;

                    continue;
                }

                if ($this->lockManager->isLockedForTenant($tenant, self::LOCK_OPERATION)) {
                    $summary['skipped_locked_count']++;

                    continue;
                }

                $summary['eligible_count']++;

                try {
                    RefreshLandlordTenantDetailSnapshotJob::dispatch(
                        tenantId: (string) $tenant->getKey(),
                        source: $this->dispatchSource($mode),
                        batchId: $batchId,
                    );
                    $summary['dispatched_count']++;

                    $this->recordAuditLog->execute(
                        action: 'landlord_tenant.detail_snapshot_batch_refresh_queued',
                        tenant: $tenant,
                        actor: $actor,
                        metadata: [
                            'batch_id' => $batchId,
                            'mode' => $mode,
                            'mode_label' => $summary['mode_label'],
                            'snapshot_status' => (string) $row->snapshot_status_resolved,
                            'priority_rank' => (int) $row->priority_rank,
                            'triggered_from' => 'landlord_snapshot_dashboard',
                        ],
                    );
                } catch (Throwable) {
                    $summary['dispatch_failed_count']++;
                }
            }

            $this->resolveResultStatus($summary);
            $this->persistBatchExecution($batchId, $mode, $actor, $summary, $filters);

            $context = array_merge($context, [
                'matched_count' => $summary['matched_count'],
                'eligible_count' => $summary['eligible_count'],
                'dispatched_count' => $summary['dispatched_count'],
                'skipped_locked_count' => $summary['skipped_locked_count'],
                'skipped_refreshing_count' => $summary['skipped_refreshing_count'],
                'skipped_healthy_count' => $summary['skipped_healthy_count'],
                'skipped_cooldown_count' => $summary['skipped_cooldown_count'],
                'dispatch_failed_count' => $summary['dispatch_failed_count'],
            ]);

            if ($summary['result_status'] === 'partially_completed') {
                $this->recordBatchRefresh->partiallyCompleted($actor, $context);
            } else {
                $this->recordBatchRefresh->completed($actor, $context);
            }

            return $summary;
        } catch (Throwable $throwable) {
            $this->recordBatchRefresh->failed($actor, $throwable, array_merge($context, [
                'matched_count' => $summary['matched_count'],
                'eligible_count' => $summary['eligible_count'],
                'dispatched_count' => $summary['dispatched_count'],
            ]));

            throw $throwable;
        } finally {
            $submissionLock->release();
        }
    }

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_SELECTED,
            self::MODE_FILTERED,
            self::MODE_CRITICAL,
            self::MODE_MISSING,
            self::MODE_STALE,
            self::MODE_FAILED,
        ];
    }

    public function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_SELECTED => 'Selecionados',
            self::MODE_FILTERED => 'Filtro atual',
            self::MODE_CRITICAL => 'Críticos',
            self::MODE_MISSING => 'Missing',
            self::MODE_STALE => 'Stale',
            self::MODE_FAILED => 'Failed',
            default => 'Lote',
        };
    }

    /**
     * @param  array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }  $filters
     * @param  list<string>  $selectedTenantIds
     */
    private function targetRowsQuery(string $mode, array $filters, array $selectedTenantIds): Builder
    {
        return match ($mode) {
            self::MODE_SELECTED => $this->buildRowsQuery
                ->filteredRowsQuery($filters)
                ->whereIn('id', $selectedTenantIds === [] ? ['__none__'] : $selectedTenantIds),
            self::MODE_FILTERED => $this->buildRowsQuery->filteredRowsQuery($filters),
            self::MODE_CRITICAL => $this->buildRowsQuery
                ->scopedRowsQuery($filters)
                ->where('priority_rank', '>=', 300),
            self::MODE_MISSING => $this->buildRowsQuery
                ->scopedRowsQuery($filters)
                ->where('snapshot_status_resolved', 'missing'),
            self::MODE_STALE => $this->buildRowsQuery
                ->scopedRowsQuery($filters)
                ->where('snapshot_status_resolved', 'stale'),
            self::MODE_FAILED => $this->buildRowsQuery
                ->scopedRowsQuery($filters)
                ->where('snapshot_status_resolved', 'failed'),
            default => $this->buildRowsQuery->filteredRowsQuery($filters),
        };
    }

    /**
     * @param  array{
     *     result_status:string,
     *     batch_id:string,
     *     mode:string,
     *     mode_label:string,
     *     filters:array<string, string>,
     *     selected_count:int,
     *     matched_count:int,
     *     eligible_count:int,
     *     dispatched_count:int,
     *     skipped_locked_count:int,
     *     skipped_refreshing_count:int,
     *     skipped_healthy_count:int,
     *     skipped_cooldown_count:int,
     *     dispatch_failed_count:int,
     *     duplicate_submission:bool
     * }  &$summary
     */
    private function incrementSkippedSummary(array &$summary, string $reason): void
    {
        if ($reason === 'refreshing') {
            $summary['skipped_refreshing_count']++;

            return;
        }

        if ($reason === 'healthy') {
            $summary['skipped_healthy_count']++;

            return;
        }

        if ($reason === 'cooldown') {
            $summary['skipped_cooldown_count']++;
        }
    }

    /**
     * @param  array{
     *     result_status:string,
     *     batch_id:string,
     *     mode:string,
     *     mode_label:string,
     *     filters:array<string, string>,
     *     selected_count:int,
     *     matched_count:int,
     *     eligible_count:int,
     *     dispatched_count:int,
     *     skipped_locked_count:int,
     *     skipped_refreshing_count:int,
     *     skipped_healthy_count:int,
     *     skipped_cooldown_count:int,
     *     dispatch_failed_count:int,
     *     duplicate_submission:bool
     * }  &$summary
     */
    private function resolveResultStatus(array &$summary): void
    {
        if (
            $summary['dispatch_failed_count'] > 0
            || (
                $summary['dispatched_count'] > 0
                && $summary['matched_count'] > $summary['dispatched_count']
            )
        ) {
            $summary['result_status'] = 'partially_completed';
        }
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function filterContext(array $filters): array
    {
        return array_filter($filters, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array{
     *     result_status:string,
     *     batch_id:string,
     *     mode:string,
     *     mode_label:string,
     *     filters:array<string, string>,
     *     selected_count:int,
     *     matched_count:int,
     *     eligible_count:int,
     *     dispatched_count:int,
     *     skipped_locked_count:int,
     *     skipped_refreshing_count:int,
     *     skipped_healthy_count:int,
     *     skipped_cooldown_count:int,
     *     dispatch_failed_count:int,
     *     duplicate_submission:bool
     * }  $summary
     * @param  array<string, string>  $filters
     */
    private function persistBatchExecution(string $batchId, string $mode, User $actor, array $summary, array $filters): void
    {
        if ($summary['dispatched_count'] === 0) {
            return;
        }

        try {
            LandlordSnapshotBatchExecution::query()->create([
                'id' => $batchId,
                'type' => $mode,
                'type_label' => $this->modeLabel($mode),
                'actor_id' => $actor->getKey(),
                'status' => 'running',
                'total_target' => $summary['matched_count'],
                'total_queued' => $summary['dispatched_count'],
                'total_succeeded' => 0,
                'total_failed' => 0,
                'total_skipped' => $summary['skipped_locked_count']
                    + $summary['skipped_refreshing_count']
                    + $summary['skipped_healthy_count']
                    + $summary['skipped_cooldown_count'],
                'metadata_json' => [
                    'filters' => $this->filterContext($filters),
                    'dispatch_failed_count' => $summary['dispatch_failed_count'],
                    'eligible_count' => $summary['eligible_count'],
                    'dispatch_skipped_count' => $summary['skipped_locked_count']
                        + $summary['skipped_refreshing_count']
                        + $summary['skipped_healthy_count']
                        + $summary['skipped_cooldown_count'],
                ],
                'started_at' => now(),
            ]);
        } catch (Throwable) {
            // Batch tracking is non-critical — do not break the dispatch flow.
        }
    }

    /**
     * @param  list<string>  $selectedTenantIds
     * @param  array<string, string>  $filters
     */
    private function submissionLockKey(User $actor, string $mode, array $filters, array $selectedTenantIds): string
    {
        return sprintf(
            'landlord:tenant-detail-snapshot-batch-refresh:%s',
            sha1(json_encode([
                'actor_id' => (string) $actor->getKey(),
                'mode' => $mode,
                'filters' => $this->filterContext($filters),
                'selected' => $selectedTenantIds,
            ], JSON_THROW_ON_ERROR)),
        );
    }

    private function dispatchSource(string $mode): string
    {
        return sprintf('batch_%s', $mode);
    }
}
