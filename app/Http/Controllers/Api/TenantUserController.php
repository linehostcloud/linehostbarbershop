<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tenant\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantMembershipResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TenantUserController extends Controller
{
    public function index(TenantContext $tenantContext): AnonymousResourceCollection
    {
        return TenantMembershipResource::collection(
            TenantMembership::query()
                ->with(['user', 'latestInvitation'])
                ->where('tenant_id', $tenantContext->current()?->id)
                ->latest()
                ->paginate(20),
        );
    }
}
