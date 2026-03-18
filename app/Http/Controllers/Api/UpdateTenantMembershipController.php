<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Auth\UpdateTenantMembershipAction;
use App\Domain\Tenant\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateTenantMembershipRequest;
use App\Http\Resources\TenantMembershipResource;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateTenantMembershipController extends Controller
{
    public function __invoke(
        string $membership,
        Request $request,
        UpdateTenantMembershipRequest $updateTenantMembershipRequest,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        UpdateTenantMembershipAction $updateTenantMembership,
    ): TenantMembershipResource {
        $membershipModel = TenantMembership::query()
            ->with(['tenant', 'user', 'latestInvitation'])
            ->where('tenant_id', $tenantContext->current()?->id)
            ->find($membership);

        if ($membershipModel === null) {
            throw new NotFoundHttpException();
        }

        return new TenantMembershipResource(
            $updateTenantMembership->execute(
                membership: $membershipModel,
                actor: $tenantAuthContext->user($request),
                payload: $updateTenantMembershipRequest->validated(),
            ),
        );
    }
}
