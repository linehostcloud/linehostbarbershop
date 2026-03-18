<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CloseOrderRequest extends FormRequest
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
            'items' => ['nullable', 'array'],
            'items.*.service_id' => ['nullable', 'string', 'exists:tenant.services,id'],
            'items.*.professional_id' => ['nullable', 'string', 'exists:tenant.professionals,id'],
            'items.*.subscription_id' => ['nullable', 'string'],
            'items.*.type' => ['sometimes', 'string', 'max:30'],
            'items.*.description' => ['required_with:items', 'string', 'max:190'],
            'items.*.quantity' => ['sometimes', 'numeric', 'gt:0'],
            'items.*.unit_price_cents' => ['required_with:items', 'integer', 'min:0'],
            'items.*.commission_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.metadata_json' => ['nullable', 'array'],
            'discount_cents' => ['sometimes', 'integer', 'min:0'],
            'fee_cents' => ['sometimes', 'integer', 'min:0'],
            'amount_paid_cents' => ['nullable', 'integer', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.provider' => ['required_with:payments', 'string', 'in:cash,pix,credit_card,debit_card,link,manual,bank_transfer'],
            'payments.*.gateway' => ['nullable', 'string', 'max:50'],
            'payments.*.external_reference' => ['nullable', 'string', 'max:191'],
            'payments.*.amount_cents' => ['required_with:payments', 'integer', 'min:1'],
            'payments.*.currency' => ['sometimes', 'string', 'size:3'],
            'payments.*.installment_count' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'payments.*.status' => ['sometimes', 'string', 'in:pending,paid,captured,settled,failed,refunded'],
            'payments.*.paid_at' => ['nullable', 'date'],
            'payments.*.due_at' => ['nullable', 'date'],
            'payments.*.failure_reason' => ['nullable', 'string', 'max:255'],
            'payments.*.metadata_json' => ['nullable', 'array'],
            'payments.*.cash_register_session_id' => ['nullable', 'string', 'exists:tenant.cash_register_sessions,id'],
            'closed_by_user_id' => ['nullable', 'string'],
            'closed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'mark_appointment_completed' => ['sometimes', 'boolean'],
        ];
    }
}
