<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use Illuminate\Support\Str;

class ProviderEndpointGuard
{
    public function assertSafe(WhatsappProviderConfig $configuration): void
    {
        $baseUrl = $configuration->base_url;

        if ($baseUrl === null || $baseUrl === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'A configuracao do provider nao possui base_url definida.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }

        $components = parse_url($baseUrl);
        $scheme = strtolower((string) ($components['scheme'] ?? ''));
        $host = strtolower((string) ($components['host'] ?? ''));
        $user = (string) ($components['user'] ?? '');
        $pass = (string) ($components['pass'] ?? '');
        $fragment = (string) ($components['fragment'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'A base_url do provider e invalida.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }

        if ($user !== '' || $pass !== '' || $fragment !== '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'A base_url do provider nao pode conter credenciais embutidas nem fragmentos.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }

        if ((bool) config('communication.whatsapp.allow_private_network_targets', false)) {
            return;
        }

        if (
            $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || Str::endsWith($host, ['.local', '.internal'])
            || Str::contains($host, ['docker', 'host.docker.internal'])
        ) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'A base_url configurada para o provider aponta para um host interno nao permitido.',
                retryable: false,
                details: [
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                ],
            ));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new WhatsappProviderException(new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ValidationError,
                    message: 'A base_url configurada para o provider aponta para um IP privado ou reservado nao permitido.',
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
}
