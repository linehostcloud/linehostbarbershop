<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Support\Carbon;

class ResolveLandlordTenantSnapshotRetryStateAction
{
    /**
     * @return array{
     *     retry_status:string,
     *     is_retrying:bool,
     *     attempt_label:string|null,
     *     retry_attempt:int,
     *     retry_max:int,
     *     next_retry_at:string|null,
     *     next_retry_in_seconds:int|null,
     *     next_retry_in_label:string|null,
     *     last_error_summary:string|null,
     *     retry_exhausted_at:string|null
     * }
     */
    public function execute(
        int $retryAttempt,
        mixed $nextRetryAt = null,
        mixed $retryExhaustedAt = null,
        ?string $lastRefreshError = null,
    ): array {
        $maxAttempts = $this->maxAttempts();
        $nextRetryAtDate = $this->asCarbon($nextRetryAt);
        $retryExhaustedAtDate = $this->asCarbon($retryExhaustedAt);

        $retryStatus = $this->resolveStatus($retryAttempt, $nextRetryAtDate, $retryExhaustedAtDate);
        $isRetrying = in_array($retryStatus, ['scheduled', 'running'], true);

        $nextRetryInSeconds = null;
        $nextRetryInLabel = null;

        if ($nextRetryAtDate !== null && $nextRetryAtDate->isFuture()) {
            $nextRetryInSeconds = max(0, (int) now()->diffInSeconds($nextRetryAtDate));
            $nextRetryInLabel = $this->durationLabel($nextRetryInSeconds);
        }

        return [
            'retry_status' => $retryStatus,
            'is_retrying' => $isRetrying,
            'attempt_label' => $retryAttempt > 0 ? sprintf('%d/%d', $retryAttempt, $maxAttempts) : null,
            'retry_attempt' => $retryAttempt,
            'retry_max' => $maxAttempts,
            'next_retry_at' => $this->formatDate($nextRetryAtDate),
            'next_retry_in_seconds' => $nextRetryInSeconds,
            'next_retry_in_label' => $nextRetryInLabel,
            'last_error_summary' => $this->summarizeError($lastRefreshError),
            'retry_exhausted_at' => $this->formatDate($retryExhaustedAtDate),
        ];
    }

    private function resolveStatus(int $retryAttempt, ?Carbon $nextRetryAt, ?Carbon $retryExhaustedAt): string
    {
        if ($retryExhaustedAt !== null) {
            return 'exhausted';
        }

        if ($retryAttempt <= 0) {
            return 'idle';
        }

        if ($nextRetryAt !== null && $nextRetryAt->isFuture()) {
            return 'scheduled';
        }

        return 'running';
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('landlord.tenants.detail_snapshot.retry_max_attempts', 4));
    }

    private function summarizeError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        $error = trim($error);

        if (mb_strlen($error) <= 120) {
            return $error;
        }

        return mb_substr($error, 0, 117).'...';
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($remaining === 0) {
            return sprintf('%dmin', $minutes);
        }

        return sprintf('%dmin %ds', $minutes, $remaining);
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
        return $value?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i:s');
    }
}
