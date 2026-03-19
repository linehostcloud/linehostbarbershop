<?php

namespace App\Http\Controllers\Api;

use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoundaryRejectionAuditResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BoundaryRejectionAuditController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext): AnonymousResourceCollection
    {
        $tenant = $tenantContext->current();

        abort_if($tenant === null, 404, 'Tenant ativo nao encontrado para consulta de auditoria de boundary.');

        $query = BoundaryRejectionAudit::query()
            ->where('tenant_id', $tenant->getKey())
            ->latest('occurred_at');

        if ($request->filled('code')) {
            $query->where('code', (string) $request->string('code'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', (string) $request->string('direction'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', (string) $request->string('provider'));
        }

        return BoundaryRejectionAuditResource::collection($query->paginate(20));
    }
}
