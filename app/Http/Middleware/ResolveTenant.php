<?php

namespace App\Http\Middleware;

use App\Application\Actions\Observability\RecordTenantOperationalBlockAuditAction;
use App\Application\Actions\Tenancy\EnsureTenantOperationalAccessAction;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;
use App\Infrastructure\Tenancy\Resolvers\RequestTenantResolver;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private readonly RequestTenantResolver $resolver,
        private readonly TenantContext $tenantContext,
        private readonly TenantDatabaseManager $databaseManager,
        private readonly EnsureTenantOperationalAccessAction $ensureTenantOperationalAccess,
        private readonly RecordTenantOperationalBlockAuditAction $recordTenantOperationalBlockAudit,
        private readonly WhatsappBoundaryRouteMatcher $boundaryRouteMatcher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            throw TenantCouldNotBeResolved::forRequest($request);
        }

        $this->tenantContext->set($tenant);

        try {
            // Tenant-aware HTTP entrypoints should concentrate enforcement here.
            $this->ensureTenantOperationalAccess->execute($tenant);
        } catch (TenantOperationalAccessDenied $exception) {
            if (! $this->boundaryRouteMatcher->matches($request)) {
                $this->recordTenantOperationalBlockAudit->execute(
                    tenant: $tenant,
                    channel: $request->is('api/*') ? 'api' : 'web',
                    outcome: 'blocked',
                    reasonCode: 'tenant_status_runtime_enforcement',
                    request: $request,
                    httpStatus: 423,
                    context: [
                        'tenant_status' => $tenant->status,
                        'enforcement_policy' => 'tenant_status_runtime_enforcement',
                        'message' => $exception->getMessage(),
                    ],
                );
            }

            throw $exception;
        }

        $this->databaseManager->connect($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
        $this->databaseManager->disconnect();
    }
}
