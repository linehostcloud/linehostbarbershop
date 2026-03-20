<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLandlordTenantStatusRequest extends FormRequest
{
    protected $errorBag = 'tenantStatusTransition';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => trim(mb_strtolower((string) $this->input('status', ''))),
            'status_reason' => trim((string) $this->input('status_reason', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['trial', 'active', 'suspended'])],
            'status_reason' => ['required', 'string', 'min:12', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Selecione a transição de status desejada.',
            'status.in' => 'O status solicitado não é suportado para governança manual.',
            'status_reason.required' => 'Informe o motivo administrativo da mudança de status.',
            'status_reason.min' => 'Descreva o motivo da mudança de status com pelo menos 12 caracteres.',
        ];
    }
}
