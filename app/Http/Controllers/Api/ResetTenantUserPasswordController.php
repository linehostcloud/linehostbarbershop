<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Auth\ResetTenantUserPasswordAction;
use App\Domain\Tenant\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResetTenantUserPasswordRequest;
use App\Http\Resources\TenantMembershipResource;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResetTenantUserPasswordController extends Controller
{
    public function __invoke(
        string $membership,
        Request $request,
        ResetTenantUserPasswordRequest $resetTenantUserPasswordRequest,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        ResetTenantUserPasswordAction $resetTenantUserPassword,
    ): JsonResponse {
        $membershipModel = TenantMembership::query()
            ->with(['tenant', 'user', 'latestInvitation'])
            ->where('tenant_id', $tenantContext->current()?->id)
            ->find($membership);

        if ($membershipModel === null) {
            throw new NotFoundHttpException();
        }

        $result = $resetTenantUserPassword->execute(
            membership: $membershipModel,
            actor: $tenantAuthContext->user($request),
            payload: $resetTenantUserPasswordRequest->validated(),
        );

        return response()->json([
            'data' => [
                'membership' => (new TenantMembershipResource($result['membership']))->resolve(),
                'temporary_password' => $result['temporary_password'],
            ],
        ]);
    }
}
