<?php

namespace App\Domain\Communication\Data;

use App\Domain\Communication\Enums\WhatsappMessageStatus;
use Carbon\CarbonImmutable;

readonly class ProviderDispatchResult
{
    /**
     * @param  array<string, mixed>  $responsePayload
     * @param  array<string, mixed>|null  $sanitizedRequestPayload
     */
    public function __construct(
        public string $provider,
        public WhatsappMessageStatus $normalizedStatus,
        public ?string $providerMessageId = null,
        public ?string $providerStatus = null,
        public ?string $requestId = null,
        public ?int $httpStatus = null,
        public ?int $latencyMs = null,
        public ?CarbonImmutable $occurredAt = null,
        public array $responsePayload = [],
        public ?array $sanitizedRequestPayload = null,
        public ?ProviderErrorData $error = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->error === null;
    }
}
