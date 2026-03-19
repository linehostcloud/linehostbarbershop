<?php

namespace App\Application\Actions\Observability;

use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Integration\Whatsapp\WhatsappBoundaryRouteMatcher;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordBoundaryRejectionAuditAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantAuthContext $tenantAuthContext,
        private readonly WhatsappBoundaryRouteMatcher $routeMatcher,
        private readonly WhatsappPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $context
     * @param  array<string, string|null>|null  $headers
     */
    public function execute(
        Request $request,
        WhatsappBoundaryRejectionCode $code,
        string $message,
        ?int $httpStatus = null,
        ?string $provider = null,
        ?string $slot = null,
        ?string $direction = null,
        ?array $payload = null,
        ?array $context = null,
        ?array $headers = null,
        ?Throwable $exception = null,
    ): ?BoundaryRejectionAudit {
        try {
            $tenant = $this->tenantContext->current();
            $actor = $this->tenantAuthContext->user($request);
            $membership = $this->tenantAuthContext->membership($request);
            $payload ??= $request->all();
            $requestId = $request->header('x-request-id')
                ?: $request->header('x-fb-trace-id')
                ?: $request->header('x-evolution-request-id');
            $correlationId = $requestId ?: (string) Str::uuid();

            return BoundaryRejectionAudit::query()->create([
                'tenant_id' => $tenant?->getKey(),
                'tenant_slug' => $tenant?->slug ?: $request->header((string) config('tenancy.identification.tenant_slug_header', 'X-Tenant-Slug')),
                'actor_user_id' => $actor?->getKey(),
                'actor_email' => $actor?->email ?: $membership?->user?->email,
                'direction' => $direction ?: $this->routeMatcher->direction($request),
                'endpoint' => $this->resolveEndpoint($request),
                'route_name' => $request->route()?->getName(),
                'method' => $request->getMethod(),
                'host' => $request->getHost(),
                'source_ip' => $request->ip(),
                'provider' => $provider ?: $this->resolveProvider($request),
                'slot' => $slot ?: $this->resolveSlot($request),
                'code' => $code->value,
                'message' => Str::limit($message, 255, ''),
                'http_status' => $httpStatus,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'payload_json' => is_array($payload) ? $this->sanitizer->sanitize($payload) : null,
                'headers_json' => $this->sanitizer->sanitizeHeaders($headers ?: $this->headersForAudit($request)),
                'context_json' => array_filter([
                    'request_body_sha256' => hash('sha256', $request->getContent() !== '' ? $request->getContent() : json_encode($payload)),
                    'exception_class' => $exception !== null ? $exception::class : null,
                    'route_parameters' => $request->route()?->parameters() ?? [],
                    ...($context ?? []),
                ], static fn (mixed $value): bool => $value !== null),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $auditFailure) {
            Log::warning('Falha ao persistir boundary rejection audit.', [
                'code' => $code->value,
                'message' => $message,
                'audit_error' => $auditFailure->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function headersForAudit(Request $request): array
    {
        $tenantHeader = (string) config('tenancy.identification.tenant_slug_header', 'X-Tenant-Slug');

        return [
            'authorization' => $request->header('authorization'),
            'content-type' => $request->header('content-type'),
            'user-agent' => $request->userAgent(),
            $tenantHeader => $request->header($tenantHeader),
            'x-request-id' => $request->header('x-request-id'),
            'x-fb-trace-id' => $request->header('x-fb-trace-id'),
            'x-hub-signature-256' => $request->header('x-hub-signature-256'),
            'x-evolution-signature' => $request->header('x-evolution-signature'),
            'x-webhook-secret' => $request->header('x-webhook-secret'),
        ];
    }

    private function resolveEndpoint(Request $request): string
    {
        return $request->route()?->uri() ?: $request->path();
    }

    private function resolveProvider(Request $request): ?string
    {
        $provider = $request->route('provider')
            ?? $request->input('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function resolveSlot(Request $request): ?string
    {
        $slot = $request->input('slot')
            ?? $request->input('provider_slot');

        return is_string($slot) && $slot !== '' ? $slot : null;
    }
}
