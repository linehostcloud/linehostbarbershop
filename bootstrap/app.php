<?php

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\AuthenticateTenantAccessToken;
use App\Http\Middleware\AuthorizeTenantAbility;
use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'tenant.auth' => AuthenticateTenantAccessToken::class,
            'tenant.ability' => AuthorizeTenantAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TenantCouldNotBeResolved $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 404);
            }

            return response($exception->getMessage(), 404);
        });
    })->create();
