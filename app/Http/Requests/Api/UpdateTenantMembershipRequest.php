<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantMembershipRequest extends FormRequest
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
            'role' => ['sometimes', 'string', 'in:owner,manager,receptionist,professional,finance,automation_admin'],
            'permissions_json' => ['nullable', 'array'],
            'permissions_json.*' => ['string', 'max:120'],
            'revoked' => ['sometimes', 'boolean'],
        ];
    }
}
