<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LandlordLoginRequest;
use App\Infrastructure\Auth\LandlordPanelAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LandlordSessionController extends Controller
{
    public function create(LandlordPanelAccess $landlordPanelAccess): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($landlordPanelAccess->canAccess($user)) {
            return redirect()->route('landlord.tenants.index');
        }

        if ($user !== null) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return view('landlord.panel.login');
    }

    public function store(
        LandlordLoginRequest $request,
        LandlordPanelAccess $landlordPanelAccess,
    ): RedirectResponse {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt([
            'email' => (string) $credentials['email'],
            'password' => (string) $credentials['password'],
        ])) {
            return back()
                ->withInput($request->safe()->only('email'))
                ->withErrors([
                    'email' => 'As credenciais informadas são inválidas.',
                ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        if (! $landlordPanelAccess->canAccess($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->safe()->only('email'))
                ->withErrors([
                    'email' => 'Seu usuário não está autorizado para o painel SaaS.',
                ]);
        }

        $user?->forceFill([
            'last_login_at' => now(),
        ])->save();

        return redirect()->intended(route('landlord.tenants.index'));
    }

    public function destroy(): RedirectResponse
    {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    }
}
