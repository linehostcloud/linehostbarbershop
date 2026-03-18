<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class InviteTenantUserRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'string', 'in:owner,manager,receptionist,professional,finance,automation_admin'],
            'permissions_json' => ['nullable', 'array'],
            'permissions_json.*' => ['string', 'max:120'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
        ];
    }
}
