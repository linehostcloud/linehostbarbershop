<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Auth\AcceptTenantInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AcceptTenantInvitationRequest;
use App\Http\Resources\TenantMembershipResource;
use Illuminate\Http\JsonResponse;

class AcceptTenantInvitationController extends Controller
{
    public function __invoke(
        AcceptTenantInvitationRequest $request,
        AcceptTenantInvitationAction $acceptTenantInvitation,
    ): JsonResponse {
        $result = $acceptTenantInvitation->execute(
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'access_token' => $result['access_token']->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result['access_token']->accessToken->expires_at?->toIso8601String(),
                'membership' => (new TenantMembershipResource($result['membership']))->resolve(),
            ],
        ]);
    }
}
