<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Auth\AuthenticateTenantCredentialsAction;
use App\Application\Actions\Auth\RevokeTenantAccessTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Infrastructure\Auth\TenantPanelAccessTokenCookieFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class TenantWhatsappOperationsPanelLoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        TenantContext $tenantContext,
        AuthenticateTenantCredentialsAction $authenticateTenantCredentials,
        TenantPanelAccessTokenCookieFactory $cookieFactory,
        RevokeTenantAccessTokenAction $revokeTenantAccessToken,
    ): RedirectResponse|Response {
        $tenant = $tenantContext->current();
        abort_if($tenant === null, 404, 'Tenant ativo nao encontrado para autenticacao.');

        $result = $authenticateTenantCredentials->execute(
            tenant: $tenant,
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            deviceName: 'painel-operacional-web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        if (! in_array('whatsapp.operations.read', $result->grantedAbilities, true) && ! in_array('*', $result->grantedAbilities, true)) {
            $revokeTenantAccessToken->execute($result->accessToken);

            return response()->view('tenant.panel.whatsapp.login-forbidden', [
                'tenant' => $tenant,
            ], 403);
        }

        return redirect()
            ->route('tenant.panel.whatsapp.operations')
            ->cookie($cookieFactory->make($result->plainTextToken, $result->accessToken->expires_at, $request));
    }
}
