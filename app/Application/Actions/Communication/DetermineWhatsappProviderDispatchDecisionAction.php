<?php

namespace App\Application\Actions\Communication;

use App\Application\DTOs\WhatsappProviderDispatchDecision;
use App\Domain\Communication\Models\Message;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappDispatchCapabilityGuard;
use Throwable;

class DetermineWhatsappProviderDispatchDecisionAction
{
    public function __construct(
        private readonly TenantWhatsappProviderResolver $providerResolver,
        private readonly CalculateWhatsappProviderHealthAction $calculateProviderHealth,
        private readonly WhatsappDispatchCapabilityGuard $capabilityGuard,
    ) {
    }

    public function execute(
        OutboxEvent $outboxEvent,
        Message $message,
        string $capability,
    ): WhatsappProviderDispatchDecision {
        $fallbackContext = $this->fallbackContext($outboxEvent);

        if ($fallbackContext !== null) {
            $resolvedProvider = $this->providerResolver->resolveBySlot((string) data_get($fallbackContext, 'to_slot', 'secondary'));

            return new WhatsappProviderDispatchDecision(
                resolvedProvider: $resolvedProvider,
                dispatchVariant: 'fallback',
                providerDecisionSource: 'fallback_pinned',
                decisionReason: 'Fallback already active for this outbox event.',
                fallbackContext: $fallbackContext,
            );
        }

        $requestedProvider = $this->requestedProvider($message);

        if ($requestedProvider !== null) {
            return new WhatsappProviderDispatchDecision(
                resolvedProvider: $this->providerResolver->resolveForOutbound($requestedProvider),
                dispatchVariant: 'manual_override',
                providerDecisionSource: 'manual_override',
                decisionReason: 'Provider explicitly requested by caller.',
            );
        }

        try {
            $resolvedPrimary = $this->providerResolver->resolveBySlot('primary');
        } catch (Throwable) {
            return new WhatsappProviderDispatchDecision(
                resolvedProvider: $this->providerResolver->resolveForOutbound($message->provider),
                dispatchVariant: 'primary',
                providerDecisionSource: 'primary_default',
                decisionReason: 'Primary route resolved from active tenant configuration.',
            );
        }

        $primaryHealth = $this->calculateProviderHealth->execute($resolvedPrimary->configuration);

        if ($primaryHealth->isHealthy()) {
            return new WhatsappProviderDispatchDecision(
                resolvedProvider: $resolvedPrimary,
                dispatchVariant: 'primary',
                providerDecisionSource: 'primary_default',
                decisionReason: 'Primary healthy.',
            );
        }

        $secondaryConfiguration = $resolvedPrimary->fallbackConfiguration;

        if ($secondaryConfiguration !== null && $secondaryConfiguration->enabled) {
            try {
                $this->capabilityGuard->assert(
                    $secondaryConfiguration->provider,
                    $secondaryConfiguration,
                    $capability,
                );

                return new WhatsappProviderDispatchDecision(
                    resolvedProvider: $this->providerResolver->resolveBySlot('secondary'),
                    dispatchVariant: 'health_secondary',
                    providerDecisionSource: 'health_based_secondary',
                    decisionReason: $this->secondaryDecisionReason($primaryHealth->stateLabel),
                );
            } catch (Throwable) {
            }
        }

        return new WhatsappProviderDispatchDecision(
            resolvedProvider: $resolvedPrimary,
            dispatchVariant: 'primary',
            providerDecisionSource: 'primary_default',
            decisionReason: sprintf('Primary %s, but no eligible secondary provider was available.', $primaryHealth->stateLabel),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fallbackContext(OutboxEvent $outboxEvent): ?array
    {
        $fallback = data_get($outboxEvent->context_json ?? [], 'whatsapp_fallback');

        if (! is_array($fallback) || ! (bool) ($fallback['active'] ?? false)) {
            return null;
        }

        return $fallback;
    }

    private function requestedProvider(Message $message): ?string
    {
        $provider = data_get($message->payload_json ?? [], 'requested_provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function secondaryDecisionReason(string $primaryState): string
    {
        return match ($primaryState) {
            'unavailable' => 'Secondary selected due to unavailable primary.',
            'unstable' => 'Primary unstable due to recent retries or fallback signals.',
            default => 'Primary degraded.',
        };
    }
}
