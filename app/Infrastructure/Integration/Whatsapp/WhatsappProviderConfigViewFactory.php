<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Data\ProviderHealthCheckResult;
use App\Domain\Communication\Models\WhatsappProviderConfig;

class WhatsappProviderConfigViewFactory
{
    public function __construct(
        private readonly WhatsappPayloadSanitizer $payloadSanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(WhatsappProviderConfig $configuration): array
    {
        return $this->base($configuration);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(WhatsappProviderConfig $configuration): array
    {
        return array_merge($this->base($configuration), [
            'retry_profile' => $configuration->retryProfile(),
            'settings' => $this->sanitizedSettings($configuration),
            'fallback' => $this->fallbackView($configuration),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(WhatsappProviderConfig $configuration): array
    {
        return $this->detail($configuration);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizeAdminPayload(array $payload): array
    {
        return $this->payloadSanitizer->sanitize($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function healthcheck(WhatsappProviderConfig $configuration, ProviderHealthCheckResult $result): array
    {
        return [
            'slot' => $configuration->slot,
            'provider' => $configuration->provider,
            'healthy' => $result->healthy,
            'http_status' => $result->httpStatus,
            'latency_ms' => $result->latencyMs,
            'details' => $this->payloadSanitizer->sanitize($result->details),
            'error' => $result->error === null ? null : [
                'code' => $result->error->code->value,
                'message' => $result->error->message,
                'retryable' => $result->error->retryable,
                'http_status' => $result->error->httpStatus,
                'provider_code' => $result->error->providerCode,
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(WhatsappProviderConfig $configuration): array
    {
        return [
            'id' => $configuration->id,
            'slot' => $configuration->slot,
            'provider' => $configuration->provider,
            'fallback_provider' => $configuration->fallback_provider,
            'base_url' => $configuration->base_url,
            'api_version' => $configuration->api_version,
            'phone_number_id' => $configuration->phone_number_id,
            'business_account_id' => $configuration->business_account_id,
            'instance_name' => $configuration->instance_name,
            'timeout_seconds' => $configuration->timeoutSeconds(),
            'enabled_capabilities' => $configuration->enabledCapabilities(),
            'enabled' => (bool) $configuration->enabled,
            'last_validated_at' => $configuration->last_validated_at?->toIso8601String(),
            'created_at' => $configuration->created_at?->toIso8601String(),
            'updated_at' => $configuration->updated_at?->toIso8601String(),
            'fallback' => $this->fallbackView($configuration),
            'secret_presence' => [
                'has_api_key' => filled($configuration->api_key),
                'has_access_token' => filled($configuration->access_token),
                'has_webhook_secret' => filled($configuration->webhook_secret),
                'has_verify_token' => filled($configuration->verify_token),
                'has_auth_password' => filled(data_get($configuration->settings_json ?? [], 'auth_password')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizedSettings(WhatsappProviderConfig $configuration): ?array
    {
        if (! is_array($configuration->settings_json)) {
            return null;
        }

        return $this->payloadSanitizer->sanitize($configuration->settings_json);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackView(WhatsappProviderConfig $configuration): array
    {
        return [
            'enabled' => $configuration->fallbackEnabled(),
            'configured_provider' => $configuration->configuredFallbackProvider(),
            'eligible_error_codes' => [
                'provider_unavailable',
                'timeout_error',
                'rate_limit',
                'transient_network_error',
            ],
        ];
    }
}
