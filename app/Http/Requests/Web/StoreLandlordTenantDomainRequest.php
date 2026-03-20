<?php

namespace App\Http\Requests\Web;

use App\Domain\Tenant\Models\TenantDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLandlordTenantDomainRequest extends FormRequest
{
    protected $errorBag = 'tenantDomains';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => mb_strtolower(trim((string) $this->input('domain', ''))),
            'make_primary' => $this->boolean('make_primary'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/',
                Rule::unique((new TenantDomain)->getConnectionName().'.tenant_domains', 'domain'),
                Rule::notIn($this->centralDomains()),
            ],
            'make_primary' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Informe o domínio que será associado ao tenant.',
            'domain.regex' => 'Informe um domínio válido, sem protocolo.',
            'domain.unique' => 'Este domínio já está vinculado a outro tenant.',
            'domain.not_in' => 'Domínios centrais do landlord não podem ser vinculados a tenants.',
        ];
    }

    /**
     * @return list<string>
     */
    private function centralDomains(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => mb_strtolower(trim($domain)),
            (array) config('tenancy.central_domains', []),
        ))));
    }
}
