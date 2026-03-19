<?php

namespace App\Application\DTOs;

readonly class WhatsappFallbackDecision
{
    public function __construct(
        public string $fromProvider,
        public string $fromSlot,
        public string $toProvider,
        public string $toSlot,
        public string $triggerErrorCode,
        public int $backoffSeconds,
        public string $reason = 'eligible_retryable_error',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'from_provider' => $this->fromProvider,
            'from_slot' => $this->fromSlot,
            'to_provider' => $this->toProvider,
            'to_slot' => $this->toSlot,
            'trigger_error_code' => $this->triggerErrorCode,
            'backoff_seconds' => $this->backoffSeconds,
        ];
    }
}
