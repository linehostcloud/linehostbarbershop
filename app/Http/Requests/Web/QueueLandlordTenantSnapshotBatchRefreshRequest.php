<?php

namespace App\Http\Requests\Web;

use App\Application\Actions\Tenancy\QueueLandlordTenantSnapshotBatchRefreshAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueueLandlordTenantSnapshotBatchRefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $selectedIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->input('selected_ids', []),
        )));

        $this->merge([
            'mode' => trim(mb_strtolower((string) $this->input('mode', ''))),
            'selected_ids' => $selectedIds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(QueueLandlordTenantSnapshotBatchRefreshAction::modes())],
            'selected_ids' => ['array'],
            'selected_ids.*' => ['string', 'max:40'],
            'snapshot_status' => ['nullable', 'string', 'max:20'],
            'tenant_status' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Selecione uma ação operacional para o refresh em lote.',
            'mode.in' => 'A ação operacional solicitada não é suportada.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (
                $this->input('mode') === QueueLandlordTenantSnapshotBatchRefreshAction::MODE_SELECTED
                && count((array) $this->input('selected_ids', [])) === 0
            ) {
                $validator->errors()->add('selected_ids', 'Selecione pelo menos um tenant para o refresh em lote.');
            }
        });
    }
}
