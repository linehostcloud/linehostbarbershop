<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantContextController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->current();

        return response()->json([
            'data' => [
                'tenant_id' => $tenant?->getKey(),
                'tenant_slug' => $tenant?->slug,
                'tenant_name' => $tenant?->trade_name,
                'resolved_host' => $request->getHost(),
                'tenant_connection' => config('tenancy.tenant_connection', 'tenant'),
                'tenant_database' => DB::connection(config('tenancy.tenant_connection', 'tenant'))->getDatabaseName(),
            ],
        ]);
    }
}
