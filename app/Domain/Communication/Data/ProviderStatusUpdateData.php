<?php

namespace App\Domain\Communication\Data;

use App\Domain\Communication\Enums\WhatsappMessageStatus;
use Carbon\CarbonImmutable;

readonly class ProviderStatusUpdateData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $providerMessageId,
        public WhatsappMessageStatus $normalizedStatus,
        public CarbonImmutable $occurredAt,
        public ?string $providerStatus = null,
        public ?ProviderErrorData $error = null,
        public ?string $phoneE164 = null,
        public array $payload = [],
    ) {
    }
}
