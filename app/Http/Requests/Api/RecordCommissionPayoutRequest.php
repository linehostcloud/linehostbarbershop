<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RecordCommissionPayoutRequest extends FormRequest
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
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'provider' => ['sometimes', 'string', 'in:cash,pix,bank_transfer,manual'],
            'cash_register_session_id' => ['nullable', 'string', 'exists:tenant.cash_register_sessions,id'],
            'occurred_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
