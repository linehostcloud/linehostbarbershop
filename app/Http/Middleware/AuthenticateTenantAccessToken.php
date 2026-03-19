<?php

namespace App\Http\Middleware;

use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Auth\Models\UserAccessToken;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPanelAccessTokenCookieFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Cookie\CookieValuePrefix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantAccessToken
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantAuthContext $tenantAuthContext,
        private readonly RecordBoundaryRejectionAuditAction $recordBoundaryRejectionAudit,
        private readonly WhatsappBoundaryRouteMatcher $routeMatcher,
        private readonly TenantPanelAccessTokenCookieFactory $cookieFactory,
        private readonly EncrypterContract $encrypter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->current();
        $bearerToken = $this->resolveToken($request);

        if ($tenant === null || blank($bearerToken)) {
            $this->audit($request, WhatsappBoundaryRejectionCode::AuthenticationFailed, 401, 'Token de acesso ausente ou invalido.');

            return $this->authenticationFailedResponse($request);
        }

        [$tokenId, $plainToken] = array_pad(explode('|', $bearerToken, 2), 2, null);

        if (blank($tokenId) || blank($plainToken)) {
            $this->audit($request, WhatsappBoundaryRejectionCode::AuthenticationFailed, 401, 'Token de acesso ausente ou invalido.');

            return $this->authenticationFailedResponse($request);
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
            $this->audit($request, WhatsappBoundaryRejectionCode::AuthenticationFailed, 401, 'Token de acesso ausente ou invalido.');

            return $this->authenticationFailedResponse($request);
        }

        $membership = $accessToken->user->memberships()
            ->with('tenant')
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($membership === null || ! $membership->isActive() || ! $accessToken->user->isActive()) {
            $this->audit($request, WhatsappBoundaryRejectionCode::AuthorizationFailed, 403, 'O usuario autenticado nao possui acesso ativo a este tenant.');

            return $this->authorizationFailedResponse($request, 'O usuario autenticado nao possui acesso ativo a este tenant.');
        }

        $accessToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        $this->tenantAuthContext->set($request, $accessToken->user, $membership, $accessToken);

        return $next($request);
    }

    private function audit(Request $request, WhatsappBoundaryRejectionCode $code, int $status, string $message): void
    {
        if (! $this->routeMatcher->matches($request)) {
            return;
        }

        $this->recordBoundaryRejectionAudit->execute(
            request: $request,
            code: $code,
            message: $message,
            httpStatus: $status,
        );
    }

    private function resolveToken(Request $request): ?string
    {
        $headerToken = $request->bearerToken();

        if (filled($headerToken)) {
            return $headerToken;
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = ltrim($request->path(), '/');

        if (
            ! str_starts_with($path, 'api/v1/operations/whatsapp')
            && ! $request->routeIs('tenant.panel.whatsapp.operations')
        ) {
            return null;
        }

        return $this->resolvePanelCookieToken($request);
    }

    private function resolvePanelCookieToken(Request $request): ?string
    {
        $cookieToken = trim((string) $request->cookie($this->cookieFactory->name(), ''));

        if ($cookieToken === '') {
            return null;
        }

        [$tokenId, $plainToken] = array_pad(explode('|', $cookieToken, 2), 2, null);

        if (filled($tokenId) && filled($plainToken)) {
            return $cookieToken;
        }

        try {
            $decryptedCookie = $this->encrypter->decrypt($cookieToken, false);
        } catch (DecryptException) {
            return null;
        }

        $validatedCookie = CookieValuePrefix::validate(
            $this->cookieFactory->name(),
            $decryptedCookie,
            $this->encrypter->getAllKeys(),
        );

        return is_string($validatedCookie) && $validatedCookie !== ''
            ? $validatedCookie
            : null;
    }

    private function authenticationFailedResponse(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
            return response()->json([
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::AuthenticationFailed->value,
                'message' => 'Token de acesso ausente ou invalido.',
            ], 401);
        }

        return redirect()->guest(route('tenant.panel.whatsapp.operations.login'));
    }

    private function authorizationFailedResponse(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
            return response()->json([
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::AuthorizationFailed->value,
                'message' => $message,
            ], 403);
        }

        return response()->view('tenant.panel.whatsapp.login-forbidden', [
            'tenant' => $this->tenantContext->current(),
        ], 403);
    }
}
