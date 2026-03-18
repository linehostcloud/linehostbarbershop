<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashMovementRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:supply,withdrawal,expense,income'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:190'],
            'occurred_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }
}
