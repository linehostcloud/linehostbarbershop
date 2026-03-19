<?php

namespace App\Domain\Communication\Data;

use App\Domain\Communication\Enums\WhatsappMessageStatus;

readonly class WhatsappStatusTransitionDecision
{
    public function __construct(
        public bool $shouldApply,
        public string $reason,
        public string $direction,
        public ?WhatsappMessageStatus $currentStatus,
        public ?WhatsappMessageStatus $incomingStatus,
    ) {
    }
}
