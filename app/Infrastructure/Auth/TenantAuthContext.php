<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;

class TenantAuthContext
{
    private const USER_ATTRIBUTE = 'tenant_auth.user';
    private const MEMBERSHIP_ATTRIBUTE = 'tenant_auth.membership';
    private const ACCESS_TOKEN_ATTRIBUTE = 'tenant_auth.access_token';

    public function set(
        Request $request,
        User $user,
        TenantMembership $membership,
        UserAccessToken $accessToken,
    ): void {
        $request->attributes->set(self::USER_ATTRIBUTE, $user);
        $request->attributes->set(self::MEMBERSHIP_ATTRIBUTE, $membership);
        $request->attributes->set(self::ACCESS_TOKEN_ATTRIBUTE, $accessToken);
        $request->setUserResolver(fn (): User => $user);
    }

    public function user(Request $request): ?User
    {
        $user = $request->attributes->get(self::USER_ATTRIBUTE);

        return $user instanceof User ? $user : null;
    }

    public function membership(Request $request): ?TenantMembership
    {
        $membership = $request->attributes->get(self::MEMBERSHIP_ATTRIBUTE);

        return $membership instanceof TenantMembership ? $membership : null;
    }

    public function accessToken(Request $request): ?UserAccessToken
    {
        $accessToken = $request->attributes->get(self::ACCESS_TOKEN_ATTRIBUTE);

        return $accessToken instanceof UserAccessToken ? $accessToken : null;
    }
}
