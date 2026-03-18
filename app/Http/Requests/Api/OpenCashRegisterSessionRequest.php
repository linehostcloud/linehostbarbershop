<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashRegisterSessionRequest extends FormRequest
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
            'label' => ['sometimes', 'string', 'max:80'],
            'opening_balance_cents' => ['sometimes', 'integer', 'min:0'],
            'opened_by_user_id' => ['nullable', 'string'],
            'opened_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
