<?php

namespace App\Http\Middleware;

use App\Infrastructure\Auth\LandlordPanelAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeLandlordAdmin
{
    public function __construct(
        private readonly LandlordPanelAccess $landlordPanelAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($this->landlordPanelAccess->canAccess($user)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->view('landlord.panel.forbidden', [], 403);
    }
}
