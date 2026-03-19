<?php

namespace App\Application\Actions\Communication;

use App\Application\DTOs\PersistedWhatsappProviderConfigResult;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigViewFactory;
use Illuminate\Support\Arr;

class PersistWhatsappProviderConfigAction
{
    /**
     * @var list<string>
     */
    private const DIRECT_SECRET_FIELDS = [
        'api_key',
        'access_token',
        'webhook_secret',
        'verify_token',
    ];

    public function __construct(
        private readonly WhatsappProviderConfigValidator $configValidator,
        private readonly WhatsappProviderConfigViewFactory $viewFactory,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): PersistedWhatsappProviderConfigResult
    {
        $configuration = new WhatsappProviderConfig();
        $configuration->fill($this->buildAttributes($payload));

        $this->configValidator->assertCanPersist($configuration);
        $configuration->last_validated_at = now();
        $configuration->save();
        $configuration->refresh();

        return new PersistedWhatsappProviderConfigResult(
            configuration: $configuration,
            created: true,
            rotatedSecretFields: [],
            before: null,
            after: $this->viewFactory->snapshot($configuration),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(WhatsappProviderConfig $configuration, array $payload): PersistedWhatsappProviderConfigResult
    {
        $before = $this->viewFactory->snapshot($configuration);
        $rotatedSecretFields = $this->detectRotatedSecretFields($configuration, $payload);

        $configuration->fill($this->buildAttributes($payload, $configuration));
        $this->configValidator->assertCanPersist($configuration);
        $configuration->last_validated_at = now();
        $configuration->save();
        $configuration->refresh();

        return new PersistedWhatsappProviderConfigResult(
            configuration: $configuration,
            created: false,
            rotatedSecretFields: $rotatedSecretFields,
            before: $before,
            after: $this->viewFactory->snapshot($configuration),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildAttributes(array $payload, ?WhatsappProviderConfig $existing = null): array
    {
        return [
            'slot' => $existing?->slot ?? (string) $payload['slot'],
            'provider' => $this->nullableString($payload, 'provider', $existing?->provider),
            'fallback_provider' => $this->nullableString($payload, 'fallback_provider', $existing?->fallback_provider),
            'base_url' => $this->nullableString($payload, 'base_url', $existing?->base_url),
            'api_version' => $this->nullableString($payload, 'api_version', $existing?->api_version),
            'api_key' => $this->nullableString($payload, 'api_key', $existing?->api_key),
            'access_token' => $this->nullableString($payload, 'access_token', $existing?->access_token),
            'phone_number_id' => $this->nullableString($payload, 'phone_number_id', $existing?->phone_number_id),
            'business_account_id' => $this->nullableString($payload, 'business_account_id', $existing?->business_account_id),
            'instance_name' => $this->nullableString($payload, 'instance_name', $existing?->instance_name),
            'webhook_secret' => $this->nullableString($payload, 'webhook_secret', $existing?->webhook_secret),
            'verify_token' => $this->nullableString($payload, 'verify_token', $existing?->verify_token),
            'timeout_seconds' => $this->integerValue($payload, 'timeout_seconds', $existing?->timeout_seconds ?? (int) config('communication.whatsapp.default_timeout_seconds', 10)),
            'retry_profile_json' => $this->mergedArrayValue($payload, 'retry_profile_json', $existing?->retry_profile_json),
            'enabled_capabilities_json' => $this->capabilitiesValue($payload, $existing?->enabled_capabilities_json),
            'settings_json' => $this->mergedArrayValue($payload, 'settings_json', $existing?->settings_json),
            'enabled' => $existing?->enabled ?? (bool) ($payload['enabled'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function detectRotatedSecretFields(WhatsappProviderConfig $configuration, array $payload): array
    {
        $rotated = [];

        foreach (self::DIRECT_SECRET_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $newValue = $this->normalizeScalar($payload[$field]);

            if (is_string($newValue) && $newValue !== '' && $configuration->{$field} !== $newValue) {
                $rotated[] = $field;
            }
        }

        if (Arr::has($payload, 'settings_json.auth_password')) {
            $newPassword = $this->normalizeScalar(data_get($payload, 'settings_json.auth_password'));

            if (is_string($newPassword) && $newPassword !== '' && $configuration->basicAuthPassword() !== $newPassword) {
                $rotated[] = 'settings_json.auth_password';
            }
        }

        return array_values(array_unique($rotated));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $this->normalizeScalar($payload[$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function integerValue(array $payload, string $key, int $default): int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return $default;
        }

        return (int) $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>|null
     */
    private function mergedArrayValue(array $payload, string $key, ?array $current = null): ?array
    {
        if (! array_key_exists($key, $payload)) {
            return $current;
        }

        if ($payload[$key] === null) {
            return null;
        }

        $incoming = $this->normalizeArray($payload[$key]);

        if (! is_array($incoming)) {
            return $current;
        }

        if (! is_array($current) || $current === []) {
            return $incoming;
        }

        return array_replace_recursive($current, $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $current
     * @return list<string>|null
     */
    private function capabilitiesValue(array $payload, ?array $current = null): ?array
    {
        if (! array_key_exists('enabled_capabilities_json', $payload)) {
            return $current;
        }

        if ($payload['enabled_capabilities_json'] === null) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(
                fn (mixed $capability): ?string => is_string($capability) ? trim($capability) : null,
                (array) $payload['enabled_capabilities_json'],
            ),
            static fn (?string $capability): bool => $capability !== null && $capability !== '',
        )));
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeArray(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_array($item)
                ? $this->normalizeArray($item)
                : $this->normalizeScalar($item);
        }

        return $value;
    }
}
