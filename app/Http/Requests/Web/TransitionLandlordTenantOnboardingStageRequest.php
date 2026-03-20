<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionLandlordTenantOnboardingStageRequest extends FormRequest
{
    protected $errorBag = 'tenantOnboardingTransition';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'onboarding_stage' => trim(mb_strtolower((string) $this->input('onboarding_stage', ''))),
            'onboarding_transition_reason' => trim((string) $this->input('onboarding_transition_reason', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'onboarding_stage' => ['required', 'string', Rule::in(['created', 'provisioned', 'completed'])],
            'onboarding_transition_reason' => ['required', 'string', 'min:12', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'onboarding_stage.required' => 'Selecione a transição de onboarding desejada.',
            'onboarding_stage.in' => 'O estágio de onboarding solicitado não é suportado para governança manual.',
            'onboarding_transition_reason.required' => 'Informe o motivo administrativo da mudança de onboarding.',
            'onboarding_transition_reason.min' => 'Descreva o motivo da mudança de onboarding com pelo menos 12 caracteres.',
        ];
    }
}
