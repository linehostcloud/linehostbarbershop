<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'client_id' => ['required', 'string', 'exists:tenant.clients,id'],
            'professional_id' => ['required', 'string', 'exists:tenant.professionals,id'],
            'primary_service_id' => ['nullable', 'string', 'exists:tenant.services,id'],
            'subscription_id' => ['nullable', 'string'],
            'booked_by_user_id' => ['nullable', 'string'],
            'source' => ['sometimes', 'string', 'max:30'],
            'status' => ['sometimes', 'string', 'max:20'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'confirmation_status' => ['sometimes', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
