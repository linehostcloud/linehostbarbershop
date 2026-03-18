<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Auth\InviteTenantUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InviteTenantUserRequest;
use App\Http\Resources\TenantMembershipResource;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteTenantUserController extends Controller
{
    public function __invoke(
        Request $request,
        InviteTenantUserRequest $inviteTenantUserRequest,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        InviteTenantUserAction $inviteTenantUser,
    ): JsonResponse {
        $result = $inviteTenantUser->execute(
            tenant: $tenantContext->current(),
            actor: $tenantAuthContext->user($request),
            payload: $inviteTenantUserRequest->validated(),
        );

        return response()->json([
            'data' => [
                'membership' => (new TenantMembershipResource($result['membership']))->resolve(),
                'invitation' => [
                    'id' => $result['invitation']->id,
                    'expires_at' => $result['invitation']->expires_at?->toIso8601String(),
                    'token' => $result['plain_text_token'],
                    'user_created' => $result['user_created'],
                ],
            ],
        ], 201);
    }
}
