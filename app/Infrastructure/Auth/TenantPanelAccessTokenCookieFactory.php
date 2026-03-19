<?php

namespace App\Infrastructure\Auth;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class TenantPanelAccessTokenCookieFactory
{
    public function name(): string
    {
        return (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token');
    }

    public function make(string $token, ?CarbonInterface $expiresAt, Request $request): Cookie
    {
        $minutes = $expiresAt !== null
            ? max(1, now()->diffInMinutes($expiresAt, false))
            : (int) config('auth.access_tokens.ttl_minutes', 10080);

        return cookie(
            name: $this->name(),
            value: $token,
            minutes: $minutes,
            path: '/',
            domain: config('session.domain'),
            secure: $request->isSecure() || (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'strict',
        );
    }

    public function forget(Request $request): Cookie
    {
        return cookie()->forget(
            name: $this->name(),
            path: '/',
            domain: config('session.domain'),
        );
    }
}
