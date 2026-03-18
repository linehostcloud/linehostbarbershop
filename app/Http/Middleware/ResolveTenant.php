<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
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
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            throw TenantCouldNotBeResolved::forRequest($request);
        }

        $this->tenantContext->set($tenant);
        $this->databaseManager->connect($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
        $this->databaseManager->disconnect();
    }
}
