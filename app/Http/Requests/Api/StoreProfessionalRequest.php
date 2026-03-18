<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfessionalRequest extends FormRequest
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
            'user_id' => ['nullable', 'string'],
            'display_name' => ['required', 'string', 'max:120'],
            'role' => ['sometimes', 'string', 'max:30'],
            'commission_model' => ['sometimes', 'string', 'max:30'],
            'commission_percent' => ['nullable', 'numeric', 'between:0,100'],
            'color_hex' => ['nullable', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'workday_calendar_json' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
            'hired_at' => ['nullable', 'date'],
            'terminated_at' => ['nullable', 'date'],
        ];
    }
}
