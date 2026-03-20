<?php

namespace App\Http\Requests\Web;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLandlordTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'trade_name' => trim((string) $this->input('trade_name', '')),
            'legal_name' => trim((string) $this->input('legal_name', '')),
            'slug' => mb_strtolower(trim((string) $this->input('slug', ''))),
            'domain' => mb_strtolower(trim((string) $this->input('domain', ''))),
            'owner_name' => trim((string) $this->input('owner_name', '')),
            'owner_email' => mb_strtolower(trim((string) $this->input('owner_email', ''))),
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
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique((new Tenant)->getConnectionName().'.tenants', 'slug'),
            ],
            'domain' => [
                'nullable',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/',
                Rule::unique((new TenantDomain)->getConnectionName().'.tenant_domains', 'domain'),
            ],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trade_name.required' => 'Informe o nome fantasia do tenant.',
            'slug.required' => 'Informe o slug do tenant.',
            'slug.regex' => 'Use apenas letras minúsculas, números e hífens no slug.',
            'slug.unique' => 'Já existe um tenant com este slug.',
            'domain.regex' => 'Informe um domínio válido, sem protocolo.',
            'domain.unique' => 'Já existe um tenant com este domínio.',
            'owner_name.required' => 'Informe o nome do owner inicial.',
            'owner_email.required' => 'Informe o email do owner inicial.',
            'owner_email.email' => 'Informe um email válido para o owner inicial.',
        ];
    }
}
