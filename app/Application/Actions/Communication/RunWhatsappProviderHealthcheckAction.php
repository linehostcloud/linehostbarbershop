<?php

namespace App\Application\Actions\Communication;

use App\Domain\Communication\Data\ProviderHealthCheckResult;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderRegistry;

class RunWhatsappProviderHealthcheckAction
{
    public function __construct(
        private readonly WhatsappProviderConfigValidator $configValidator,
        private readonly WhatsappProviderRegistry $providerRegistry,
    ) {
    }

    public function execute(WhatsappProviderConfig $configuration): ProviderHealthCheckResult
    {
        $this->configValidator->assertCanPersist($configuration);

        return $this->providerRegistry
            ->resolve($configuration->provider)
            ->healthCheck($configuration);
    }
}
