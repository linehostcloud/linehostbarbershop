<?php

use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Http\Middleware\AuthenticateTenantAccessToken;
use App\Http\Middleware\AuthorizeLandlordAdmin;
use App\Http\Middleware\AuthorizeTenantAbility;
use App\Http\Middleware\EnsureCentralDomain;
use App\Http\Middleware\ResolveTenant;
use App\Infrastructure\Integration\Whatsapp\BoundaryRejectionCodeResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use App\Infrastructure\Tenancy\Exceptions\TenantCouldNotBeResolved;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'landlord.central' => EnsureCentralDomain::class,
            'landlord.admin' => AuthorizeLandlordAdmin::class,
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
                    code: WhatsappBoundaryRejectionCode::TenantUnresolved,
                    message: $exception->getMessage(),
                    httpStatus: 404,
                    direction: $matcher->direction($request),
                    exception: $exception,
                );
            }

            if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
                return response()->json([
                    'status' => 'rejected',
                    'boundary_rejection_code' => WhatsappBoundaryRejectionCode::TenantUnresolved->value,
                    'message' => $exception->getMessage(),
                ], 404);
            }

            return response($exception->getMessage(), 404);
        });

        $exceptions->render(function (TenantOperationalAccessDenied $exception, Request $request) {
            $matcher = app(WhatsappBoundaryRouteMatcher::class);
            $isWebhook = $matcher->isWebhook($request);
            $isOutbound = $matcher->isOutbound($request);
            $operationalChannel = $isWebhook
                ? 'webhook'
                : ($isOutbound ? 'outbound' : ($request->is('api/*') ? 'api' : 'web'));
            $responseStatus = $isWebhook ? 202 : 423;
            $responseState = $isWebhook ? 'ignored' : 'rejected';

            Log::notice('Tenant operational access denied.', [
                'tenant_id' => $exception->tenant->getKey(),
                'tenant_slug' => $exception->tenant->slug,
                'tenant_status' => $exception->tenant->status,
                'operational_channel' => $operationalChannel,
                'route_name' => $request->route()?->getName(),
                'endpoint' => $request->route()?->uri() ?: $request->path(),
                'method' => $request->getMethod(),
                'host' => $request->getHost(),
                'source_ip' => $request->ip(),
                'http_status' => $responseStatus,
            ]);

            if ($matcher->matches($request)) {
                app(RecordBoundaryRejectionAuditAction::class)->execute(
                    request: $request,
                    code: WhatsappBoundaryRejectionCode::SecurityPolicyViolation,
                    message: $exception->getMessage(),
                    httpStatus: $responseStatus,
                    direction: $matcher->direction($request),
                    context: [
                        'tenant_status' => $exception->tenant->status,
                        'tenant_id' => $exception->tenant->getKey(),
                        'tenant_slug' => $exception->tenant->slug,
                        'operational_channel' => $operationalChannel,
                        'enforcement_outcome' => $isWebhook ? 'ignored_without_processing' : 'blocked',
                        'enforcement_policy' => 'tenant_status_runtime_enforcement',
                    ],
                    exception: $exception,
                );
            }

            if ($request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')) {
                if ($isWebhook) {
                    return response()->json([
                        'status' => 'ignored',
                        'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                        'message' => 'Webhook ignorado com seguranca porque o tenant esta suspenso.',
                        'tenant_status' => $exception->tenant->status,
                    ], 202);
                }

                if ($matcher->matches($request)) {
                    return response()->json([
                        'status' => $responseState,
                        'boundary_rejection_code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
                        'message' => $exception->getMessage(),
                        'tenant_status' => $exception->tenant->status,
                    ], $responseStatus);
                }

                return response()->json([
                    'message' => $exception->getMessage(),
                    'tenant_status' => $exception->tenant->status,
                ], 423);
            }

            return response()->view('tenant.panel.whatsapp.tenant-suspended', [
                'tenant' => $exception->tenant,
            ], 423);
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
                'normalized_error_code' => WhatsappProviderErrorCode::ValidationError->value,
                'message' => $exception->getMessage(),
                'boundary_rejection_code' => $code->value,
                'errors' => $exception->errors(),
            ], 422);
        });
    })->create();
