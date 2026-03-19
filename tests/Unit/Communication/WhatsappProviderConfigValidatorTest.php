<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use Tests\TestCase;

class WhatsappProviderConfigValidatorTest extends TestCase
{
    public function test_it_rejects_private_hosts_when_private_network_targets_are_disabled(): void
    {
        config()->set('communication.whatsapp.allow_private_network_targets', false);

        $configuration = WhatsappProviderConfig::make([
            'provider' => 'evolution_api',
            'base_url' => 'http://127.0.0.1:8080',
            'api_key' => 'evo-api-key',
            'instance_name' => 'barbearia-demo',
            'enabled' => true,
        ]);

        $this->expectException(WhatsappProviderException::class);
        $this->expectExceptionMessage('host interno nao permitido');

        app(WhatsappProviderConfigValidator::class)->assertCanPersist($configuration);
    }

    public function test_it_rejects_capabilities_that_are_only_prepared_and_not_operational(): void
    {
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'evolution_api',
            'base_url' => 'https://evolution.example',
            'api_key' => 'evo-api-key',
            'instance_name' => 'barbearia-demo',
            'enabled_capabilities_json' => ['text', 'instance_management'],
            'enabled' => true,
        ]);

        $this->expectException(WhatsappProviderException::class);
        $this->expectExceptionMessage('preparada, mas ainda nao operacional');

        app(WhatsappProviderConfigValidator::class)->assertCanPersist($configuration);
    }
}
