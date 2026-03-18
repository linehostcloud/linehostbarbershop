<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Actions\Auth\RevokeTenantAccessTokenAction;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogoutController extends Controller
{
    public function __invoke(
        Request $request,
        TenantAuthContext $tenantAuthContext,
        RevokeTenantAccessTokenAction $revokeTenantAccessToken,
    ): Response {
        $accessToken = $tenantAuthContext->accessToken($request);

        if ($accessToken !== null) {
            $revokeTenantAccessToken->execute($accessToken);
        }

        return response()->noContent();
    }
}
