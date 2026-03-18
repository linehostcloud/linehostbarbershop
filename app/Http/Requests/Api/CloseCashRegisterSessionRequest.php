<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashRegisterSessionRequest extends FormRequest
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
            'counted_cash_cents' => ['required', 'integer', 'min:0'],
            'closed_by_user_id' => ['nullable', 'string'],
            'closed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
