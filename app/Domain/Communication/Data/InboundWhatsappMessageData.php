<?php

namespace App\Domain\Communication\Data;

use Carbon\CarbonImmutable;

readonly class InboundWhatsappMessageData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $providerMessageId,
        public string $threadKey,
        public ?string $phoneE164,
        public string $type,
        public ?string $bodyText,
        public CarbonImmutable $occurredAt,
        public array $payload = [],
    ) {
    }
}
