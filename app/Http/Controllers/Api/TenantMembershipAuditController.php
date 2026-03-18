<?php

namespace App\Http\Controllers\Api;

use App\Domain\Auth\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TenantMembershipAuditController extends Controller
{
    public function __invoke(TenantContext $tenantContext): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            AuditLog::query()
                ->with('actor')
                ->where('tenant_id', $tenantContext->current()?->id)
                ->latest()
                ->paginate(20),
        );
    }
}
