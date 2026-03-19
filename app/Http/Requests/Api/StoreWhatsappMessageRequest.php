<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhatsappMessageRequest extends FormRequest
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
        $providers = array_values(array_unique(array_merge(
            (array) config('communication.whatsapp.testing_providers', []),
            (array) config('communication.whatsapp.supported_providers', []),
        )));

        return [
            'client_id' => ['required', 'string', 'exists:tenant.clients,id'],
            'appointment_id' => ['nullable', 'string', 'exists:tenant.appointments,id'],
            'automation_id' => ['nullable', 'string', 'exists:tenant.automations,id'],
            'campaign_id' => ['nullable', 'string', 'exists:tenant.campaigns,id'],
            'provider' => ['nullable', 'string', Rule::in($providers)],
            'thread_key' => ['nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'string', Rule::in(['text', 'template', 'media'])],
            'body_text' => ['required_without_all:payload_json.template_name,payload_json.media_url', 'nullable', 'string'],
            'payload_json' => ['nullable', 'array'],
        ];
    }
}
