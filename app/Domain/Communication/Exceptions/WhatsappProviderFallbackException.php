<?php

namespace App\Domain\Communication\Exceptions;

use App\Application\DTOs\WhatsappFallbackDecision;
use App\Domain\Communication\Data\ProviderErrorData;

class WhatsappProviderFallbackException extends WhatsappProviderException
{
    public function __construct(
        public readonly WhatsappFallbackDecision $fallbackDecision,
        ProviderErrorData $error,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error, $previous);
    }
}
