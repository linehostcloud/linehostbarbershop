<?php

namespace App\Application\DTOs;

use App\Domain\Communication\Data\ResolvedWhatsappProvider;

readonly class WhatsappProviderDispatchDecision
{
    /**
     * @param  array<string, mixed>|null  $fallbackContext
     */
    public function __construct(
        public ResolvedWhatsappProvider $resolvedProvider,
        public string $dispatchVariant,
        public string $providerDecisionSource,
        public string $decisionReason,
        public ?array $fallbackContext = null,
    ) {
    }
}
