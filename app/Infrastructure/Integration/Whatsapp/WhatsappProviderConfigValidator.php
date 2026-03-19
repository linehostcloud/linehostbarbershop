<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;

class WhatsappProviderConfigValidator
{
    public function __construct(
        private readonly ProviderEndpointGuard $endpointGuard,
        private readonly WhatsappProviderRegistry $providerRegistry,
        private readonly WhatsappProviderCapabilityMatrix $capabilityMatrix,
    ) {
    }

    public function assertCanPersist(WhatsappProviderConfig $configuration): void
    {
        $this->assertRegisteredProviders($configuration);
        $this->assertTimeout($configuration);
        $this->assertRequiredFields($configuration);
        $this->assertCapabilities($configuration);

        if (! $this->isTestingProvider($configuration->provider)) {
            $this->endpointGuard->assertSafe($configuration);

            if ($configuration->provider === 'whatsapp_cloud' && ! str_starts_with((string) $configuration->base_url, 'https://')) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'WhatsApp Cloud requer base_url HTTPS.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }
        }
    }

    public function assertUsable(WhatsappProviderConfig $configuration): void
    {
        $this->assertCanPersist($configuration);
    }

    private function assertRegisteredProviders(WhatsappProviderConfig $configuration): void
    {
        $this->providerRegistry->assertRegistered($configuration->provider);

        if ($configuration->fallback_provider !== null && $configuration->fallback_provider !== '') {
            $this->providerRegistry->assertRegistered($configuration->fallback_provider);
        }
    }

    private function assertTimeout(WhatsappProviderConfig $configuration): void
    {
        if ($configuration->timeoutSeconds() > 120) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'timeout_seconds acima do limite de seguranca de 120 segundos.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderConfigInvalid->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }
    }

    private function assertRequiredFields(WhatsappProviderConfig $configuration): void
    {
        if ($this->isTestingProvider($configuration->provider)) {
            return;
        }

        $missing = match ($configuration->provider) {
            'whatsapp_cloud' => $this->missingFields([
                'base_url' => $configuration->base_url,
                'access_token' => $configuration->access_token,
                'phone_number_id' => $configuration->phone_number_id,
            ]),
            'evolution_api' => $this->missingFields([
                'base_url' => $configuration->base_url,
                'api_key' => $configuration->api_key,
                'instance_name' => $configuration->instance_name,
            ]),
            'gowa' => $this->missingFields([
                'base_url' => $configuration->base_url,
                'settings.auth_username' => $configuration->basicAuthUsername(),
                'settings.auth_password' => $configuration->basicAuthPassword(),
            ]),
            default => [],
        };

        if ($missing !== []) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: sprintf(
                    'Configuracao do provider "%s" incompleta. Campos obrigatorios ausentes: %s.',
                    $configuration->provider,
                    implode(', ', $missing),
                ),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderConfigInvalid->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                    'missing_fields' => $missing,
                ],
            ));
        }
    }

    private function assertCapabilities(WhatsappProviderConfig $configuration): void
    {
        $enabled = $configuration->enabledCapabilities();

        foreach ($enabled as $capability) {
            if (! $this->capabilityMatrix->isImplemented($configuration->provider, $capability)) {
                $state = $this->capabilityMatrix->isPrepared($configuration->provider, $capability)
                    ? 'preparada, mas ainda nao operacional'
                    : 'nao suportada';

                throw new WhatsappProviderException(new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ValidationError,
                    message: sprintf(
                        'A capability "%s" para o provider "%s" esta %s e nao pode ser habilitada na configuracao do tenant.',
                        $capability,
                        $configuration->provider,
                        $state,
                    ),
                    retryable: false,
                    details: [
                        'boundary_rejection_code' => WhatsappBoundaryRejectionCode::CapabilityNotSupported->value,
                        'provider' => $configuration->provider,
                        'slot' => $configuration->slot,
                        'capability' => $capability,
                    ],
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function missingFields(array $fields): array
    {
        return array_keys(array_filter($fields, static function (mixed $value): bool {
            return $value === null || $value === '';
        }));
    }

    private function isTestingProvider(string $provider): bool
    {
        return in_array($provider, (array) config('communication.whatsapp.testing_providers', []), true);
    }
}
