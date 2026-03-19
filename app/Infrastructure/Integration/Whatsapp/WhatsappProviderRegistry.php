<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Contracts\WhatsappProvider;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Enums\WhatsappProviderName;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Infrastructure\Integration\Whatsapp\Providers\EvolutionApiWhatsappProvider;
use App\Infrastructure\Integration\Whatsapp\Providers\FakeWhatsappProvider;
use App\Infrastructure\Integration\Whatsapp\Providers\GoWaWhatsappProvider;
use App\Infrastructure\Integration\Whatsapp\Providers\WhatsappCloudProvider;
use Illuminate\Contracts\Container\Container;
use ValueError;

class WhatsappProviderRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function resolve(string $provider): WhatsappProvider
    {
        try {
            return match (WhatsappProviderName::from($provider)) {
                WhatsappProviderName::Fake => new FakeWhatsappProvider(
                    $this->container->make(WhatsappProviderCapabilityMatrix::class),
                    'fake',
                    false,
                ),
                WhatsappProviderName::FakeTransientFailure => new FakeWhatsappProvider(
                    $this->container->make(WhatsappProviderCapabilityMatrix::class),
                    'fake-transient-failure',
                    true,
                ),
                WhatsappProviderName::WhatsAppCloud => $this->container->make(WhatsappCloudProvider::class),
                WhatsappProviderName::EvolutionApi => $this->container->make(EvolutionApiWhatsappProvider::class),
                WhatsappProviderName::GoWa => $this->container->make(GoWaWhatsappProvider::class),
            };
        } catch (ValueError) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: sprintf('Provider de WhatsApp "%s" nao esta registrado.', $provider),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderInvalid->value,
                    'provider' => $provider,
                ],
            ));
        }
    }

    public function supports(string $provider): bool
    {
        return in_array($provider, WhatsappProviderName::values(), true);
    }

    public function assertRegistered(string $provider): void
    {
        if (! $this->supports($provider)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: sprintf('Provider de WhatsApp "%s" nao esta registrado.', $provider),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::ProviderInvalid->value,
                    'provider' => $provider,
                ],
            ));
        }
    }
}
