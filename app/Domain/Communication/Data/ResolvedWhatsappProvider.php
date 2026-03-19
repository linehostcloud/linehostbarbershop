<?php

namespace App\Domain\Communication\Data;

use App\Domain\Communication\Contracts\WhatsappProvider;
use App\Domain\Communication\Models\WhatsappProviderConfig;

readonly class ResolvedWhatsappProvider
{
    public function __construct(
        public WhatsappProvider $provider,
        public WhatsappProviderConfig $configuration,
        public ?WhatsappProviderConfig $fallbackConfiguration = null,
    ) {
    }
}
