<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
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
            'external_code' => ['nullable', 'string', 'max:40'],
            'full_name' => ['required', 'string', 'max:160'],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'birth_date' => ['nullable', 'date'],
            'preferred_professional_id' => ['nullable', 'string', 'exists:tenant.professionals,id'],
            'acquisition_channel' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'retention_status' => ['sometimes', 'string', 'max:20'],
        ];
    }
}
