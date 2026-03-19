<?php

namespace App\Domain\Communication\Data;

use Carbon\CarbonImmutable;

readonly class NormalizedWhatsappWebhookData
{
    /**
     * @param  list<InboundWhatsappMessageData>  $inboundMessages
     * @param  list<ProviderStatusUpdateData>  $statusUpdates
     * @param  list<string>  $ignoredProviderStatuses
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $eventType,
        public array $inboundMessages,
        public array $statusUpdates,
        public CarbonImmutable $receivedAt,
        public ?string $requestId = null,
        public array $ignoredProviderStatuses = [],
        public array $payload = [],
    ) {
    }
}
