<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AcceptTenantInvitationRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:200'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:8'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
