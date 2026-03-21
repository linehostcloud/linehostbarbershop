<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;

class ResolveLandlordTenantDetailSnapshotAction
{
    public function __construct(
        private readonly ResolveLandlordTenantDetailSnapshotStateAction $resolveSnapshotState,
        private readonly ResolveLandlordTenantSnapshotRetryStateAction $resolveRetryState,
    ) {}

    /**
     * @return array{
     *     model:LandlordTenantDetailSnapshot|null,
     *     payload:array<string, mixed>,
     *     has_payload:bool,
     *     status:string,
     *     label:string,
     *     detail:string,
     *     generated_at:string|null,
     *     generated_at_iso:string|null,
     *     age_seconds:int|null,
     *     is_stale:bool,
     *     stale_after_seconds:int,
     *     last_refresh_started_at:string|null,
     *     last_refresh_completed_at:string|null,
     *     last_refresh_failed_at:string|null,
     *     last_refresh_error:string|null
     * }
     */
    public function execute(Tenant $tenant): array
    {
        $tenant->loadMissing('detailSnapshot');

        /** @var LandlordTenantDetailSnapshot|null $snapshot */
        $snapshot = $tenant->detailSnapshot;
        $payload = is_array($snapshot?->payload_json) ? $snapshot->payload_json : [];
        $hasPayload = $payload !== [];
        $state = $this->resolveSnapshotState->execute(
            refreshStatus: $snapshot?->refresh_status,
            hasPayload: $hasPayload,
            generatedAt: $snapshot?->generated_at,
            lastRefreshStartedAt: $snapshot?->last_refresh_started_at,
            lastRefreshCompletedAt: $snapshot?->last_refresh_completed_at,
            lastRefreshFailedAt: $snapshot?->last_refresh_failed_at,
            lastRefreshError: $snapshot?->last_refresh_error,
        );

        $retryState = $this->resolveRetryState->execute(
            retryAttempt: (int) ($snapshot?->retry_attempt ?? 0),
            nextRetryAt: $snapshot?->next_retry_at,
            retryExhaustedAt: $snapshot?->retry_exhausted_at,
            lastRefreshError: $snapshot?->last_refresh_error,
        );

        return array_merge([
            'model' => $snapshot,
            'payload' => $payload,
            'has_payload' => $hasPayload,
            'retry' => $retryState,
        ], $state);
    }
}
