<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Actions\Auth\IssueTenantAccessTokenAction;
use App\Domain\Tenant\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Infrastructure\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        TenantContext $tenantContext,
        IssueTenantAccessTokenAction $issueTenantAccessToken,
        TenantPermissionMatrix $tenantPermissionMatrix,
    ): JsonResponse {
        $tenant = $tenantContext->current();
        $credentials = $request->validated();

        $user = User::query()
            ->where('email', strtolower(trim($credentials['email'])))
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'As credenciais informadas sao invalidas.',
            ]);
        }

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'O usuario informado esta bloqueado ou inativo.',
            ], 403);
        }

        $membership = $user->memberships()
            ->where('tenant_id', $tenant?->id)
            ->first();

        if (! $membership instanceof TenantMembership || ! $membership->isActive()) {
            return response()->json([
                'message' => 'O usuario informado nao possui acesso ativo a este tenant.',
            ], 403);
        }

        $issuedToken = $issueTenantAccessToken->execute($user, $membership->tenant()->firstOrFail(), [
            'name' => $credentials['device_name'] ?? 'api',
            'abilities' => ['*'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $issuedToken->plainTextToken,
                'expires_at' => $issuedToken->accessToken->expires_at?->toIso8601String(),
                'tenant' => [
                    'id' => $membership->tenant->id,
                    'slug' => $membership->tenant->slug,
                    'trade_name' => $membership->tenant->trade_name,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'locale' => $user->locale,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'role' => $membership->role,
                    'is_primary' => $membership->is_primary,
                    'accepted_at' => $membership->accepted_at?->toIso8601String(),
                    'permissions' => $membership->permissions_json ?? [],
                    'granted_abilities' => $tenantPermissionMatrix->grantedAbilities($membership),
                ],
            ],
        ], 201);
    }
}
