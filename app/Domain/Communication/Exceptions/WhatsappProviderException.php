<?php

namespace App\Domain\Communication\Exceptions;

use App\Domain\Communication\Data\ProviderErrorData;
use RuntimeException;

class WhatsappProviderException extends RuntimeException
{
    public function __construct(
        public readonly ProviderErrorData $error,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error->message, previous: $previous);
    }

    public function isRetryable(): bool
    {
        return $this->error->retryable;
    }
}
