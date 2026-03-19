<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;
use ValueError;

class BoundaryRejectionCodeResolver
{
    public function resolve(Throwable $throwable, Request $request): WhatsappBoundaryRejectionCode
    {
        return match (true) {
            $throwable instanceof TenantCouldNotBeResolved => WhatsappBoundaryRejectionCode::TenantUnresolved,
            $throwable instanceof ValidationException => $this->forValidationException($throwable),
            $throwable instanceof WhatsappProviderException => $this->forProviderException($throwable, $request),
            default => WhatsappBoundaryRejectionCode::UnknownBoundaryError,
        };
    }

    private function forValidationException(ValidationException $exception): WhatsappBoundaryRejectionCode
    {
        $errors = $exception->errors();

        if (array_key_exists('provider', $errors)) {
            return WhatsappBoundaryRejectionCode::ProviderInvalid;
        }

        return WhatsappBoundaryRejectionCode::PayloadInvalid;
    }

    private function forProviderException(WhatsappProviderException $exception, Request $request): WhatsappBoundaryRejectionCode
    {
        $hint = $exception->error->details['boundary_rejection_code'] ?? null;

        if (is_string($hint) && $hint !== '') {
            try {
                return WhatsappBoundaryRejectionCode::from($hint);
            } catch (ValueError) {
                // Fallback para o mapeamento por erro normalizado logo abaixo.
            }
        }

        return match ($exception->error->code) {
            WhatsappProviderErrorCode::AuthenticationError => WhatsappBoundaryRejectionCode::AuthenticationFailed,
            WhatsappProviderErrorCode::AuthorizationError => WhatsappBoundaryRejectionCode::AuthorizationFailed,
            WhatsappProviderErrorCode::WebhookSignatureInvalid => WhatsappBoundaryRejectionCode::WebhookSignatureInvalid,
            WhatsappProviderErrorCode::UnsupportedFeature => str_contains($request->path(), 'messages/whatsapp')
                ? WhatsappBoundaryRejectionCode::CapabilityNotSupported
                : WhatsappBoundaryRejectionCode::UnknownBoundaryError,
            WhatsappProviderErrorCode::ValidationError => WhatsappBoundaryRejectionCode::ProviderConfigInvalid,
            default => WhatsappBoundaryRejectionCode::UnknownBoundaryError,
        };
    }
}
