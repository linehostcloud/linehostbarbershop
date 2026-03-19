<?php

use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\AuthenticateTenantAccessToken;
use App\Http\Middleware\AuthorizeTenantAbility;
use App\Infrastructure\Integration\Whatsapp\BoundaryRejectionCodeResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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
            $matcher = app(WhatsappBoundaryRouteMatcher::class);

            if ($matcher->matches($request)) {
                app(RecordBoundaryRejectionAuditAction::class)->execute(
                    request: $request,
                    code: \App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode::TenantUnresolved,
                    message: $exception->getMessage(),
                    httpStatus: 404,
                    direction: $matcher->direction($request),
                    exception: $exception,
                );
            }

            if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
                return response()->json([
                    'status' => 'rejected',
                    'boundary_rejection_code' => \App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode::TenantUnresolved->value,
                    'message' => $exception->getMessage(),
                ], 404);
            }

            return response($exception->getMessage(), 404);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            $matcher = app(WhatsappBoundaryRouteMatcher::class);

            if (! $matcher->matches($request)) {
                return null;
            }

            $code = app(BoundaryRejectionCodeResolver::class)->resolve($exception, $request);

            app(RecordBoundaryRejectionAuditAction::class)->execute(
                request: $request,
                code: $code,
                message: $exception->getMessage(),
                httpStatus: 422,
                direction: $matcher->direction($request),
                context: [
                    'validation_errors' => $exception->errors(),
                ],
                exception: $exception,
            );

            return response()->json([
                'status' => 'rejected',
                'normalized_error_code' => \App\Domain\Communication\Enums\WhatsappProviderErrorCode::ValidationError->value,
                'message' => $exception->getMessage(),
                'boundary_rejection_code' => $code->value,
                'errors' => $exception->errors(),
            ], 422);
        });
    })->create();
