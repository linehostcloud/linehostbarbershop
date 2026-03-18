<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(
        Request $request,
        TenantAuthContext $tenantAuthContext,
        TenantPermissionMatrix $tenantPermissionMatrix,
    ): JsonResponse {
        $user = $tenantAuthContext->user($request);
        $membership = $tenantAuthContext->membership($request);
        $accessToken = $tenantAuthContext->accessToken($request);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user?->id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'status' => $user?->status,
                    'locale' => $user?->locale,
                ],
                'membership' => [
                    'id' => $membership?->id,
                    'role' => $membership?->role,
                    'is_primary' => $membership?->is_primary,
                    'accepted_at' => $membership?->accepted_at?->toIso8601String(),
                    'permissions' => $membership?->permissions_json ?? [],
                    'granted_abilities' => $membership === null
                        ? []
                        : $tenantPermissionMatrix->grantedAbilities($membership),
                ],
                'token' => [
                    'id' => $accessToken?->id,
                    'name' => $accessToken?->name,
                    'last_used_at' => $accessToken?->last_used_at?->toIso8601String(),
                    'expires_at' => $accessToken?->expires_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}
