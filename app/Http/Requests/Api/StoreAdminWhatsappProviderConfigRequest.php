<?php

namespace App\Http\Requests\Api;

use App\Domain\Communication\Enums\WhatsappCapability;
use App\Domain\Communication\Enums\WhatsappProviderName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminWhatsappProviderConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slot' => ['required', 'string', Rule::in(['primary', 'secondary'])],
            'provider' => ['required', 'string', Rule::in(WhatsappProviderName::values())],
            'fallback_provider' => ['nullable', 'string', Rule::in(WhatsappProviderName::values()), 'different:provider'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:40'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'phone_number_id' => ['nullable', 'string', 'max:120'],
            'business_account_id' => ['nullable', 'string', 'max:120'],
            'instance_name' => ['nullable', 'string', 'max:120'],
            'webhook_secret' => ['nullable', 'string', 'max:4096'],
            'verify_token' => ['nullable', 'string', 'max:4096'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'retry_profile_json' => ['nullable', 'array'],
            'retry_profile_json.max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'retry_profile_json.retry_backoff_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'enabled_capabilities_json' => ['nullable', 'array'],
            'enabled_capabilities_json.*' => ['string', Rule::in($this->capabilityValues())],
            'settings_json' => ['nullable', 'array'],
            'settings_json.auth_username' => ['nullable', 'string', 'max:255'],
            'settings_json.auth_password' => ['nullable', 'string', 'max:4096'],
            'settings_json.healthcheck_path' => ['nullable', 'string', 'max:255'],
            'settings_json.fallback' => ['nullable', 'array'],
            'settings_json.fallback.enabled' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function capabilityValues(): array
    {
        return array_map(
            static fn (WhatsappCapability $capability): string => $capability->value,
            WhatsappCapability::cases(),
        );
    }
}
