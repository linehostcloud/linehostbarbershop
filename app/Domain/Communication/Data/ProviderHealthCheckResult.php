<?php

namespace App\Domain\Communication\Data;

readonly class ProviderHealthCheckResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $healthy,
        public ?int $httpStatus = null,
        public ?int $latencyMs = null,
        public array $details = [],
        public ?ProviderErrorData $error = null,
    ) {
    }
}
