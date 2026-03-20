<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLandlordTenantBasicsRequest extends FormRequest
{
    protected $errorBag = 'tenantBasics';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'trade_name' => trim((string) $this->input('trade_name', '')),
            'legal_name' => trim((string) $this->input('legal_name', '')),
            'timezone' => trim((string) $this->input('timezone', '')),
            'currency' => mb_strtoupper(trim((string) $this->input('currency', ''))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trade_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'timezone' => ['required', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trade_name.required' => 'Informe o nome fantasia do tenant.',
            'timezone.required' => 'Informe a timezone operacional do tenant.',
            'timezone.in' => 'Informe uma timezone válida suportada pela aplicação.',
            'currency.required' => 'Informe a moeda operacional do tenant.',
            'currency.size' => 'Use um código de moeda com 3 letras, como BRL.',
            'currency.regex' => 'Use um código de moeda com 3 letras, como BRL.',
        ];
    }
}
