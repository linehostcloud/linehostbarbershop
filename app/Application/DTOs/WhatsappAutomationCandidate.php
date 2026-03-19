<?php

namespace App\Application\DTOs;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Client\Models\Client;

readonly class WhatsappAutomationCandidate
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $status,
        public string $targetType,
        public string $targetId,
        public string $triggerReason,
        public ?string $skipReason,
        public ?Client $client,
        public ?Appointment $appointment,
        public array $context,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->status === 'eligible';
    }
}
