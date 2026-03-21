<?php

namespace App\Application\Actions\Tenancy;

class ResolveLandlordTenantSnapshotRetryDelayAction
{
    /**
     * @return array{
     *     eligible:bool,
     *     attempt:int,
     *     max_attempts:int,
     *     delay_seconds:int|null,
     *     reason:string|null
     * }
     */
    public function execute(int $currentAttempt, bool $retryable): array
    {
        $maxAttempts = $this->maxAttempts();

        if (! $retryable) {
            return [
                'eligible' => false,
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
                'delay_seconds' => null,
                'reason' => 'persistent_failure',
            ];
        }

        if ($currentAttempt >= $maxAttempts) {
            return [
                'eligible' => false,
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
                'delay_seconds' => null,
                'reason' => 'max_attempts_reached',
            ];
        }

        $delaySeconds = $this->backoffDelayForAttempt($currentAttempt);

        return [
            'eligible' => true,
            'attempt' => $currentAttempt,
            'max_attempts' => $maxAttempts,
            'delay_seconds' => $delaySeconds,
            'reason' => null,
        ];
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('landlord.tenants.detail_snapshot.retry_max_attempts', 4));
    }

    /**
     * @return list<int>
     */
    public function backoffSchedule(): array
    {
        $schedule = config('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);

        if (! is_array($schedule) || $schedule === []) {
            return [60, 300, 900];
        }

        return array_values(array_map('intval', $schedule));
    }

    private function backoffDelayForAttempt(int $currentAttempt): int
    {
        $schedule = $this->backoffSchedule();
        $index = max(0, $currentAttempt - 1);

        return $schedule[$index] ?? end($schedule);
    }
}
