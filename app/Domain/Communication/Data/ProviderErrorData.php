<?php

namespace App\Domain\Communication\Data;

use App\Domain\Communication\Enums\WhatsappProviderErrorCode;

readonly class ProviderErrorData
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public WhatsappProviderErrorCode $code,
        public string $message,
        public bool $retryable,
        public ?int $httpStatus = null,
        public ?string $providerCode = null,
        public ?string $providerStatus = null,
        public ?string $requestId = null,
        public array $details = [],
    ) {
    }
}
