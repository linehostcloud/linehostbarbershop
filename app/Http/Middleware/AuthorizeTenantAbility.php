<?php

namespace App\Http\Middleware;

use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeTenantAbility
{
    public function __construct(
        private readonly TenantAuthContext $tenantAuthContext,
        private readonly TenantPermissionMatrix $tenantPermissionMatrix,
        private readonly RecordBoundaryRejectionAuditAction $recordBoundaryRejectionAudit,
        private readonly WhatsappBoundaryRouteMatcher $routeMatcher,
    ) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $membership = $this->tenantAuthContext->membership($request);
        $accessToken = $this->tenantAuthContext->accessToken($request);

        if ($membership === null || $accessToken === null) {
            $this->audit(
                request: $request,
                code: WhatsappBoundaryRejectionCode::AuthenticationFailed,
                status: 401,
                message: 'O usuario autenticado nao possui contexto de tenant ativo.',
            );

            return $this->authenticationFailedResponse($request);
        }

        foreach ($abilities as $ability) {
            if (
                $this->tenantPermissionMatrix->hasAbility($membership, $ability)
                && $accessToken->canAccess($ability)
            ) {
                return $next($request);
            }
        }

        $this->audit(
            request: $request,
            code: WhatsappBoundaryRejectionCode::AuthorizationFailed,
            status: 403,
            message: 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.',
        );

        if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
            return response()->json([
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::AuthorizationFailed->value,
                'message' => 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.',
            ], 403);
        }

        return response()->view('tenant.panel.whatsapp.login-forbidden', [
            'tenant' => $membership?->tenant,
        ], 403);
    }

    private function audit(
        Request $request,
        WhatsappBoundaryRejectionCode $code,
        int $status,
        string $message,
    ): void
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

    private function authenticationFailedResponse(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
            return response()->json([
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::AuthenticationFailed->value,
                'message' => 'O usuario autenticado nao possui contexto de tenant ativo.',
            ], 401);
        }

        return redirect()->guest(route('tenant.panel.whatsapp.operations.login'));
    }
}
