<?php

namespace App\Http\Middleware;

use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeTenantAbility
{
    public function __construct(
        private readonly TenantAuthContext $tenantAuthContext,
        private readonly TenantPermissionMatrix $tenantPermissionMatrix,
    ) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $membership = $this->tenantAuthContext->membership($request);
        $accessToken = $this->tenantAuthContext->accessToken($request);

        if ($membership === null || $accessToken === null) {
            return response()->json([
                'message' => 'O usuario autenticado nao possui contexto de tenant ativo.',
            ], 401);
        }

        foreach ($abilities as $ability) {
            if (
                $this->tenantPermissionMatrix->hasAbility($membership, $ability)
                && $accessToken->canAccess($ability)
            ) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.',
        ], 403);
    }
}
