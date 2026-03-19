<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Auth\RevokeTenantAccessTokenAction;
use App\Domain\Auth\Models\UserAccessToken;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantPanelAccessTokenCookieFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantWhatsappOperationsPanelLogoutController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        TenantPanelAccessTokenCookieFactory $cookieFactory,
        RevokeTenantAccessTokenAction $revokeTenantAccessToken,
    ): RedirectResponse {
        $tenant = $tenantContext->current();
        $token = trim((string) $request->cookie($cookieFactory->name(), ''));

        if ($tenant !== null && $token !== '') {
            [$tokenId, $plainToken] = array_pad(explode('|', $token, 2), 2, null);

            $accessToken = filled($tokenId) && filled($plainToken)
                ? UserAccessToken::query()->find($tokenId)
                : null;

            if (
                $accessToken instanceof UserAccessToken
                && $accessToken->tenant_id === $tenant->id
                && hash_equals($accessToken->token_hash, hash('sha256', (string) $plainToken))
            ) {
                $revokeTenantAccessToken->execute($accessToken);
            }
        }

        return redirect()
            ->route('tenant.panel.whatsapp.operations.login')
            ->cookie($cookieFactory->forget($request));
    }
}
