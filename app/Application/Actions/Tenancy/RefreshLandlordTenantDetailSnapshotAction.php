<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Observability\RecordLandlordTenantDetailSnapshotRefreshAction;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use Illuminate\Support\Str;
use Throwable;

class RefreshLandlordTenantDetailSnapshotAction
{
    private const LOCK_OPERATION = 'landlord_tenant_detail_snapshot_refresh';

    public function __construct(
        private readonly BuildLandlordTenantDetailSnapshotPayloadAction $buildSnapshotPayload,
        private readonly TenantExecutionLockManager $lockManager,
        private readonly RecordLandlordTenantDetailSnapshotRefreshAction $recordRefresh,
        private readonly LandlordTenantDetailPerformanceTracker $detailPerformanceTracker,
    ) {}

    /**
     * @return array{
     *     status:string,
     *     refreshed:bool,
     *     lock_key:string,
     *     duration_ms:int|null,
     *     snapshot:LandlordTenantDetailSnapshot|null
     * }
     */
    public function execute(Tenant $tenant, string $source = 'manual'): array
    {
        $lockSeconds = max(1, (int) config('landlord.tenants.detail_snapshot.lock_seconds', 300));
        $lockResult = $this->lockManager->executeForTenant(
            tenant: $tenant,
            operation: self::LOCK_OPERATION,
            seconds: $lockSeconds,
            callback: fn (): array => $this->refreshLocked($tenant, $source),
        );

        if (! $lockResult['acquired']) {
            $this->recordRefresh->skippedDueToLock($tenant, [
                'source' => $source,
                'lock_key' => $lockResult['lock_key'],
            ]);

            return [
                'status' => 'skipped_locked',
                'refreshed' => false,
                'lock_key' => $lockResult['lock_key'],
                'duration_ms' => null,
                'snapshot' => null,
            ];
        }

        return array_merge($lockResult['result'], [
            'lock_key' => $lockResult['lock_key'],
        ]);
    }

    /**
     * @return array{
     *     status:string,
     *     refreshed:bool,
     *     duration_ms:int,
     *     snapshot:LandlordTenantDetailSnapshot
     * }
     */
    private function refreshLocked(Tenant $tenant, string $source): array
    {
        $this->detailPerformanceTracker->reset();

        $snapshot = LandlordTenantDetailSnapshot::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
        ]);
        $startedAt = now();
        $timerStartedAt = hrtime(true);

        $snapshot->fill([
            'refresh_status' => 'refreshing',
            'last_refresh_source' => $source,
            'last_refresh_started_at' => $startedAt,
        ]);
        $snapshot->save();

        $this->recordRefresh->started($tenant, [
            'source' => $source,
        ]);

        try {
            $payload = $this->buildSnapshotPayload->execute($tenant);
            $completedAt = now();
            $durationMs = $this->elapsedMilliseconds($timerStartedAt);

            $snapshot->fill([
                'refresh_status' => 'ready',
                'last_refresh_source' => $source,
                'last_refresh_error' => null,
                'payload_json' => $payload,
                'generated_at' => $completedAt,
                'last_refresh_completed_at' => $completedAt,
                'last_refresh_failed_at' => null,
            ]);
            $snapshot->save();

            $this->recordRefresh->completed($tenant, [
                'source' => $source,
                'duration_ms' => $durationMs,
                'generated_at' => $completedAt->toIso8601String(),
                'section_count' => count($payload),
            ]);

            return [
                'status' => 'completed',
                'refreshed' => true,
                'duration_ms' => $durationMs,
                'snapshot' => $snapshot->fresh(),
            ];
        } catch (Throwable $throwable) {
            $failedAt = now();
            $durationMs = $this->elapsedMilliseconds($timerStartedAt);

            $snapshot->fill([
                'refresh_status' => 'failed',
                'last_refresh_source' => $source,
                'last_refresh_error' => Str::limit($throwable->getMessage(), 255, ''),
                'last_refresh_failed_at' => $failedAt,
            ]);
            $snapshot->save();

            $this->recordRefresh->failed($tenant, $throwable, [
                'source' => $source,
                'duration_ms' => $durationMs,
            ]);

            throw $throwable;
        } finally {
            $this->detailPerformanceTracker->reset();
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
