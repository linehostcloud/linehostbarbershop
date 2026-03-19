<?php

namespace App\Application\DTOs;

readonly class WhatsappProviderHealthSnapshot
{
    /**
     * @param  array<int, array{code:string,total:int}>  $signalTotals
     * @param  array<int, array{code:string,total:int}>  $topErrorCodes
     */
    public function __construct(
        public OperationalWindow $window,
        public string $provider,
        public string $slot,
        public bool $enabled,
        public int $sendAttemptsTotal,
        public int $successesRecent,
        public int $failuresRecent,
        public int $retriesRecent,
        public int $fallbacksRecent,
        public int $fallbackScheduledTotal,
        public int $fallbackExecutedTotal,
        public int $timeoutRecent,
        public int $rateLimitRecent,
        public int $unavailableRecent,
        public int $transientRecent,
        public array $signalTotals,
        public array $topErrorCodes,
        public ?string $lastAttemptAt,
        public string $stateLabel,
        public string $stateReason,
    ) {
    }

    public function successRate(): float
    {
        if ($this->sendAttemptsTotal <= 0) {
            return 0.0;
        }

        return round(($this->successesRecent / $this->sendAttemptsTotal) * 100, 2);
    }

    public function failureRate(): float
    {
        if ($this->sendAttemptsTotal <= 0) {
            return 0.0;
        }

        return round(($this->failuresRecent / $this->sendAttemptsTotal) * 100, 2);
    }

    public function isHealthy(): bool
    {
        return $this->stateLabel === 'healthy';
    }
}
