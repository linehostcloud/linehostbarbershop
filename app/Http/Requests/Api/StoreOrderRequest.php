<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
            'client_id' => ['nullable', 'string', 'exists:tenant.clients,id'],
            'appointment_id' => ['nullable', 'string', 'exists:tenant.appointments,id'],
            'primary_professional_id' => ['nullable', 'string', 'exists:tenant.professionals,id'],
            'opened_by_user_id' => ['nullable', 'string'],
            'origin' => ['sometimes', 'string', 'max:20'],
            'opened_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
