<?php

namespace App\Application\Actions\Communication;

use App\Application\DTOs\WhatsappFallbackDecision;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Data\ResolvedWhatsappProvider;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Integration\Whatsapp\WhatsappDispatchCapabilityGuard;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;

class DetermineWhatsappFallbackDecisionAction
{
    /**
     * @var list<WhatsappProviderErrorCode>
     */
    private const FALLBACK_ELIGIBLE_ERROR_CODES = [
        WhatsappProviderErrorCode::ProviderUnavailable,
        WhatsappProviderErrorCode::TimeoutError,
        WhatsappProviderErrorCode::RateLimit,
        WhatsappProviderErrorCode::TransientNetworkError,
    ];

    public function __construct(
        private readonly WhatsappProviderConfigValidator $configValidator,
        private readonly WhatsappDispatchCapabilityGuard $capabilityGuard,
    ) {
    }

    public function execute(
        OutboxEvent $outboxEvent,
        ResolvedWhatsappProvider $resolvedProvider,
        string $capability,
        ProviderErrorData $error,
    ): ?WhatsappFallbackDecision {
        $primaryConfiguration = $resolvedProvider->configuration;
        $fallbackConfiguration = $resolvedProvider->fallbackConfiguration;

        if ($primaryConfiguration->slot !== 'primary') {
            return null;
        }

        if (! $primaryConfiguration->fallbackEnabled()) {
            return null;
        }

        if ($this->fallbackAlreadyActivated($outboxEvent)) {
            return null;
        }

        if (! $error->retryable || ! in_array($error->code, self::FALLBACK_ELIGIBLE_ERROR_CODES, true)) {
            return null;
        }

        if ($outboxEvent->attempt_count >= $outboxEvent->max_attempts) {
            return null;
        }

        if ($fallbackConfiguration === null || ! $fallbackConfiguration->enabled || $fallbackConfiguration->slot !== 'secondary') {
            return null;
        }

        $configuredFallbackProvider = $primaryConfiguration->configuredFallbackProvider();

        if ($configuredFallbackProvider !== null && $configuredFallbackProvider !== $fallbackConfiguration->provider) {
            return null;
        }

        try {
            $this->configValidator->assertUsable($fallbackConfiguration);
            $this->capabilityGuard->assert(
                $fallbackConfiguration->provider,
                $fallbackConfiguration,
                $capability,
            );
        } catch (\Throwable) {
            return null;
        }

        return new WhatsappFallbackDecision(
            fromProvider: $primaryConfiguration->provider,
            fromSlot: $primaryConfiguration->slot,
            toProvider: $fallbackConfiguration->provider,
            toSlot: $fallbackConfiguration->slot,
            triggerErrorCode: $error->code->value,
            backoffSeconds: $primaryConfiguration->retryProfile()['retry_backoff_seconds'],
        );
    }

    private function fallbackAlreadyActivated(OutboxEvent $outboxEvent): bool
    {
        return (bool) data_get($outboxEvent->context_json ?? [], 'whatsapp_fallback.active', false);
    }
}
