<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
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
            'category' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'commissionable' => ['sometimes', 'boolean'],
            'default_commission_percent' => ['nullable', 'numeric', 'between:0,100'],
            'requires_subscription' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
