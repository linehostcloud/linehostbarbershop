<?php

namespace App\Http\Requests\Api;

use App\Domain\Automation\Enums\WhatsappAutomationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminWhatsappAutomationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'conditions' => ['sometimes', 'array'],
            'conditions.lead_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:43200'],
            'conditions.selection_tolerance_minutes' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'conditions.excluded_statuses' => ['sometimes', 'array'],
            'conditions.excluded_statuses.*' => ['string', 'max:40'],
            'conditions.inactivity_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'conditions.minimum_completed_visits' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'conditions.require_marketing_opt_in' => ['sometimes', 'boolean'],
            'conditions.exclude_with_future_appointments' => ['sometimes', 'boolean'],
            'message' => ['sometimes', 'array'],
            'message.type' => ['sometimes', 'string', Rule::in(['text', 'template', 'media'])],
            'message.body_text' => ['sometimes', 'nullable', 'string'],
            'message.payload_json' => ['sometimes', 'array'],
            'message.provider' => ['sometimes', 'nullable', 'string', Rule::in($providers)],
            'cooldown_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = (string) $this->route('type');

            if (! in_array($type, WhatsappAutomationType::values(), true)) {
                $validator->errors()->add('type', 'O tipo de automacao informado nao e suportado.');
            }
        });
    }
}
