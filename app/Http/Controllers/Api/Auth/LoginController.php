<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Actions\Auth\AuthenticateTenantCredentialsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        TenantContext $tenantContext,
        AuthenticateTenantCredentialsAction $authenticateTenantCredentials,
    ): JsonResponse {
        $tenant = $tenantContext->current();
        $credentials = $request->validated();
        abort_if($tenant === null, 404, 'Tenant ativo nao encontrado para autenticacao.');
        $result = $authenticateTenantCredentials->execute(
            tenant: $tenant,
            email: (string) $credentials['email'],
            password: (string) $credentials['password'],
            deviceName: $credentials['device_name'] ?? 'api',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $result->plainTextToken,
                'expires_at' => $result->accessToken->expires_at?->toIso8601String(),
                'tenant' => [
                    'id' => $result->membership->tenant->id,
                    'slug' => $result->membership->tenant->slug,
                    'trade_name' => $result->membership->tenant->trade_name,
                ],
                'user' => [
                    'id' => $result->user->id,
                    'name' => $result->user->name,
                    'email' => $result->user->email,
                    'status' => $result->user->status,
                    'locale' => $result->user->locale,
                ],
                'membership' => [
                    'id' => $result->membership->id,
                    'role' => $result->membership->role,
                    'is_primary' => $result->membership->is_primary,
                    'accepted_at' => $result->membership->accepted_at?->toIso8601String(),
                    'permissions' => $result->membership->permissions_json ?? [],
                    'granted_abilities' => $result->grantedAbilities,
                ],
            ],
        ], 201);
    }
}
