<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Support\Carbon;

class DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction
{
    /**
     * @return array{
     *     dispatchable:bool,
     *     reason:string|null,
     *     cooldown_seconds:int,
     *     latest_refresh_activity_at:string|null
     * }
     */
    public function execute(
        string $snapshotStatus,
        mixed $lastRefreshStartedAt = null,
        mixed $lastRefreshCompletedAt = null,
        mixed $lastRefreshFailedAt = null,
    ): array {
        if ($snapshotStatus === 'refreshing') {
            return [
                'dispatchable' => false,
                'reason' => 'refreshing',
                'cooldown_seconds' => $this->cooldownSeconds(),
                'latest_refresh_activity_at' => $this->formatDate($this->latestRefreshActivityAt(
                    $lastRefreshStartedAt,
                    $lastRefreshCompletedAt,
                    $lastRefreshFailedAt,
                )),
            ];
        }

        if ($snapshotStatus === 'ready') {
            return [
                'dispatchable' => false,
                'reason' => 'healthy',
                'cooldown_seconds' => $this->cooldownSeconds(),
                'latest_refresh_activity_at' => $this->formatDate($this->latestRefreshActivityAt(
                    $lastRefreshStartedAt,
                    $lastRefreshCompletedAt,
                    $lastRefreshFailedAt,
                )),
            ];
        }

        $latestRefreshActivityAt = $this->latestRefreshActivityAt(
            $lastRefreshStartedAt,
            $lastRefreshCompletedAt,
            $lastRefreshFailedAt,
        );
        $cooldownSeconds = $this->cooldownSeconds();

        if (
            $cooldownSeconds > 0
            && $latestRefreshActivityAt !== null
            && $latestRefreshActivityAt->diffInSeconds(now()) < $cooldownSeconds
        ) {
            return [
                'dispatchable' => false,
                'reason' => 'cooldown',
                'cooldown_seconds' => $cooldownSeconds,
                'latest_refresh_activity_at' => $this->formatDate($latestRefreshActivityAt),
            ];
        }

        return [
            'dispatchable' => true,
            'reason' => null,
            'cooldown_seconds' => $cooldownSeconds,
            'latest_refresh_activity_at' => $this->formatDate($latestRefreshActivityAt),
        ];
    }

    public function cooldownSeconds(): int
    {
        return max(0, (int) config('landlord.tenants.detail_snapshot.batch_refresh_cooldown_seconds', 120));
    }

    private function latestRefreshActivityAt(
        mixed $lastRefreshStartedAt,
        mixed $lastRefreshCompletedAt,
        mixed $lastRefreshFailedAt,
    ): ?Carbon {
        return collect([
            $this->asCarbon($lastRefreshStartedAt),
            $this->asCarbon($lastRefreshCompletedAt),
            $this->asCarbon($lastRefreshFailedAt),
        ])
            ->filter(fn (mixed $value): bool => $value instanceof Carbon)
            ->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())
            ->first();
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function formatDate(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }
}
