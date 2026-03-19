<?php

namespace App\Domain\Communication\Models;

use App\Domain\Communication\Enums\WhatsappCapability;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class WhatsappProviderConfig extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slot',
        'provider',
        'fallback_provider',
        'base_url',
        'api_version',
        'api_key',
        'access_token',
        'phone_number_id',
        'business_account_id',
        'instance_name',
        'webhook_secret',
        'verify_token',
        'timeout_seconds',
        'retry_profile_json',
        'enabled_capabilities_json',
        'settings_json',
        'enabled',
        'last_validated_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
        'access_token',
        'webhook_secret',
        'verify_token',
        'settings_json',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'verify_token' => 'encrypted',
            'retry_profile_json' => 'array',
            'enabled_capabilities_json' => 'array',
            'settings_json' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    public function timeoutSeconds(): int
    {
        return max(1, (int) ($this->timeout_seconds ?: config('communication.whatsapp.default_timeout_seconds', 10)));
    }

    /**
     * @return array{max_attempts:int,retry_backoff_seconds:int}
     */
    public function retryProfile(): array
    {
        $defaults = [
            'max_attempts' => (int) config('observability.outbox.default_max_attempts', 5),
            'retry_backoff_seconds' => (int) config('observability.outbox.default_retry_backoff_seconds', 60),
        ];

        $profile = is_array($this->retry_profile_json) ? $this->retry_profile_json : [];

        return [
            'max_attempts' => max(1, (int) ($profile['max_attempts'] ?? $defaults['max_attempts'])),
            'retry_backoff_seconds' => max(1, (int) ($profile['retry_backoff_seconds'] ?? $defaults['retry_backoff_seconds'])),
        ];
    }

    /**
     * @return list<string>
     */
    public function enabledCapabilities(): array
    {
        if (! is_array($this->enabled_capabilities_json) || $this->enabled_capabilities_json === []) {
            return [];
        }

        return array_values(array_filter(
            $this->enabled_capabilities_json,
            static fn (mixed $capability): bool => is_string($capability) && $capability !== '',
        ));
    }

    public function capabilityEnabled(WhatsappCapability|string $capability): bool
    {
        $value = $capability instanceof WhatsappCapability ? $capability->value : $capability;
        $enabled = $this->enabledCapabilities();

        return $enabled === [] || in_array($value, $enabled, true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings_json ?? [], $key, $default);
    }

    public function basicAuthUsername(): ?string
    {
        $value = $this->setting('auth_username');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function basicAuthPassword(): ?string
    {
        $value = $this->setting('auth_password');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function fallbackEnabled(): bool
    {
        return filter_var($this->setting('fallback.enabled', false), FILTER_VALIDATE_BOOL);
    }

    public function configuredFallbackProvider(): ?string
    {
        $provider = $this->fallback_provider;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }
}
