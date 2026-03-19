<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappCapability;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;

class WhatsappDispatchCapabilityGuard
{
    public function __construct(
        private readonly WhatsappProviderCapabilityMatrix $capabilityMatrix,
    ) {
    }

    public function capabilityForMessageType(string $type): string
    {
        return match ($type) {
            'text' => WhatsappCapability::Text->value,
            'template' => WhatsappCapability::Template->value,
            'media' => WhatsappCapability::Media->value,
            default => throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::UnsupportedFeature,
                message: sprintf('Tipo de mensagem "%s" nao e suportado.', $type),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::CapabilityNotSupported->value,
                    'message_type' => $type,
                ],
            )),
        };
    }

    public function assert(string $provider, WhatsappProviderConfig $configuration, string $capability): void
    {
        if (! $this->capabilityMatrix->isImplemented($provider, $capability)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::UnsupportedFeature,
                message: sprintf(
                    'O provider "%s" nao implementa a capability "%s".',
                    $provider,
                    $capability,
                ),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::CapabilityNotSupported->value,
                    'provider' => $provider,
                    'capability' => $capability,
                    'slot' => $configuration->slot,
                ],
            ));
        }

        if (! $configuration->capabilityEnabled($capability)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::UnsupportedFeature,
                message: sprintf(
                    'A capability "%s" nao esta habilitada para o provider "%s" na configuracao do tenant.',
                    $capability,
                    $provider,
                ),
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::CapabilityNotEnabled->value,
                    'provider' => $provider,
                    'capability' => $capability,
                    'slot' => $configuration->slot,
                ],
            ));
        }
    }
}
