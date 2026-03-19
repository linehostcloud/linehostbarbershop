<?php

namespace App\Domain\Communication\Data;

use Carbon\CarbonImmutable;

readonly class ReceivedWhatsappWebhookData
{
    /**
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public array $headers,
        public array $payload,
        public string $rawBody,
        public CarbonImmutable $receivedAt,
    ) {
    }
}
