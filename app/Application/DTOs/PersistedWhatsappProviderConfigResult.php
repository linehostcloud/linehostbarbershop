<?php

namespace App\Application\DTOs;

use App\Domain\Communication\Models\WhatsappProviderConfig;

readonly class PersistedWhatsappProviderConfigResult
{
    /**
     * @param  list<string>  $rotatedSecretFields
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public WhatsappProviderConfig $configuration,
        public bool $created,
        public array $rotatedSecretFields,
        public ?array $before,
        public array $after,
    ) {
    }
}
