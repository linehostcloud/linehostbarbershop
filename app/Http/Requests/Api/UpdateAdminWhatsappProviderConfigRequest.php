<?php

namespace App\Http\Requests\Api;

use App\Domain\Communication\Enums\WhatsappCapability;
use App\Domain\Communication\Enums\WhatsappProviderName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminWhatsappProviderConfigRequest extends FormRequest
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
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(WhatsappProviderName::values())],
            'fallback_provider' => ['sometimes', 'nullable', 'string', Rule::in(WhatsappProviderName::values()), 'different:provider'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'api_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'business_account_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'instance_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'verify_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'retry_profile_json' => ['sometimes', 'nullable', 'array'],
            'retry_profile_json.max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'retry_profile_json.retry_backoff_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3600'],
            'enabled_capabilities_json' => ['sometimes', 'nullable', 'array'],
            'enabled_capabilities_json.*' => ['string', Rule::in($this->capabilityValues())],
            'settings_json' => ['sometimes', 'nullable', 'array'],
            'settings_json.auth_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings_json.auth_password' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'settings_json.healthcheck_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings_json.fallback' => ['sometimes', 'nullable', 'array'],
            'settings_json.fallback.enabled' => ['sometimes', 'nullable', 'boolean'],
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
