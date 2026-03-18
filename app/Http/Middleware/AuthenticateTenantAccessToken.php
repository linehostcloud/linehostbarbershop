<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\UserAccessToken;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantAccessToken
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantAuthContext $tenantAuthContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->current();
        $bearerToken = $request->bearerToken();

        if ($tenant === null || blank($bearerToken)) {
            return response()->json([
                'message' => 'Token de acesso ausente ou invalido.',
            ], 401);
        }

        [$tokenId, $plainToken] = array_pad(explode('|', $bearerToken, 2), 2, null);

        if (blank($tokenId) || blank($plainToken)) {
            return response()->json([
                'message' => 'Token de acesso ausente ou invalido.',
            ], 401);
        }

        $accessToken = UserAccessToken::query()
            ->with('user')
            ->find($tokenId);

        if (
            ! $accessToken instanceof UserAccessToken
            || $accessToken->tenant_id !== $tenant->id
            || ! hash_equals($accessToken->token_hash, hash('sha256', $plainToken))
            || $accessToken->isExpired()
        ) {
            return response()->json([
                'message' => 'Token de acesso ausente ou invalido.',
            ], 401);
        }

        $membership = $accessToken->user->memberships()
            ->with('tenant')
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($membership === null || ! $membership->isActive() || ! $accessToken->user->isActive()) {
            return response()->json([
                'message' => 'O usuario autenticado nao possui acesso ativo a este tenant.',
            ], 403);
        }

        $accessToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        $this->tenantAuthContext->set($request, $accessToken->user, $membership, $accessToken);

        return $next($request);
    }
}
