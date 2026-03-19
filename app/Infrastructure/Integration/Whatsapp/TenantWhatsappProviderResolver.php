<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Data\ResolvedWhatsappProvider;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;

class TenantWhatsappProviderResolver
{
    public function __construct(
        private readonly WhatsappProviderRegistry $registry,
        private readonly WhatsappProviderConfigValidator $configValidator,
        private readonly WhatsappProviderCapabilityMatrix $capabilityMatrix,
    ) {
    }

    public function resolveForOutbound(?string $requestedProvider = null): ResolvedWhatsappProvider
    {
        $provider = $requestedProvider !== null ? trim($requestedProvider) : null;

        if ($provider !== null && $provider !== '' && $this->isTestingProvider($provider)) {
            return $this->makeResolved($this->ephemeralTestingConfig($provider));
        }

        if ($provider !== null && $provider !== '') {
            $this->registry->assertRegistered($provider);
        }

        $primary = WhatsappProviderConfig::query()
            ->where('slot', 'primary')
            ->where('enabled', true)
            ->first();
        $secondary = WhatsappProviderConfig::query()
            ->where('slot', 'secondary')
            ->where('enabled', true)
            ->first();

        if ($provider === null || $provider === '') {
            if ($primary !== null) {
                return $this->makeResolved($primary, $secondary);
            }

            return $this->resolveTestingFallback();
        }

        $configured = WhatsappProviderConfig::query()
            ->where('provider', $provider)
            ->where('enabled', true)
            ->first();

        if ($configured !== null) {
            return $this->makeResolved($configured, $configured->slot === 'primary' ? $secondary : null);
        }

        throw new WhatsappProviderException(new ProviderErrorData(
            code: WhatsappProviderErrorCode::ValidationError,
            message: sprintf('O tenant nao possui configuracao ativa para o provider "%s".', $provider),
            retryable: false,
            details: [
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderConfigMissing->value,
                'provider' => $provider,
                'slot' => 'primary',
            ],
        ));
    }

    public function resolveForWebhook(string $provider): ResolvedWhatsappProvider
    {
        $provider = trim($provider);

        if ($provider === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'Provider de webhook nao informado.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderInvalid->value,
                ],
            ));
        }

        if ($this->isTestingProvider($provider)) {
            return $this->makeResolved($this->ephemeralTestingConfig($provider));
        }

        $this->registry->assertRegistered($provider);

        $configured = WhatsappProviderConfig::query()
            ->where('provider', $provider)
            ->where('enabled', true)
            ->first();

        if ($configured === null) {
            $activeProviders = WhatsappProviderConfig::query()
                ->where('enabled', true)
                ->pluck('provider')
                ->all();

            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: $activeProviders !== []
                    ? sprintf(
                        'Webhook recebido para o provider "%s", mas o tenant possui configuracao ativa para: %s.',
                        $provider,
                        implode(', ', $activeProviders),
                    )
                    : sprintf('O tenant nao possui configuracao ativa para o provider "%s".', $provider),
                retryable: false,
                details: [
                    'boundary_rejection_code' => $activeProviders !== []
                        ? WhatsappBoundaryRejectionCode::EndpointMismatch->value
                        : WhatsappBoundaryRejectionCode::ProviderConfigMissing->value,
                    'provider' => $provider,
                ],
            ));
        }

        return $this->makeResolved($configured);
    }

    private function resolveTestingFallback(): ResolvedWhatsappProvider
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'Nenhum provider de WhatsApp esta configurado para o tenant.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderConfigMissing->value,
                    'slot' => 'primary',
                ],
            ));
        }

        $provider = (string) config('communication.whatsapp.default_testing_provider', 'fake');

        return $this->makeResolved($this->ephemeralTestingConfig($provider));
    }

    private function makeResolved(WhatsappProviderConfig $configuration, ?WhatsappProviderConfig $fallback = null): ResolvedWhatsappProvider
    {
        $this->registry->assertRegistered($configuration->provider);
        $this->configValidator->assertUsable($configuration);

        return new ResolvedWhatsappProvider(
            provider: $this->registry->resolve($configuration->provider),
            configuration: $configuration,
            fallbackConfiguration: $fallback,
        );
    }

    private function ephemeralTestingConfig(string $provider): WhatsappProviderConfig
    {
        if (! $this->isTestingProvider($provider)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: sprintf('O provider "%s" nao pode ser usado como fallback de teste.', $provider),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderInvalid->value,
                    'provider' => $provider,
                ],
            ));
        }

        return WhatsappProviderConfig::make([
            'slot' => 'primary',
            'provider' => $provider,
            'timeout_seconds' => (int) config('communication.whatsapp.default_timeout_seconds', 10),
            'retry_profile_json' => [
                'max_attempts' => (int) config('observability.outbox.default_max_attempts', 5),
                'retry_backoff_seconds' => (int) config('observability.outbox.default_retry_backoff_seconds', 60),
            ],
            'enabled_capabilities_json' => $this->capabilityMatrix->implementedFor($provider),
            'enabled' => true,
        ]);
    }

    private function isTestingProvider(string $provider): bool
    {
        return in_array($provider, (array) config('communication.whatsapp.testing_providers', []), true);
    }
}
