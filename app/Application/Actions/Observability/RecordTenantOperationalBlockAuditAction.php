<?php

namespace App\Application\Actions\Observability;

use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordTenantOperationalBlockAuditAction
{
    /**
     * Use esta trilha para bloqueios do enforcement operacional tenant-aware fora da borda WhatsApp.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        Tenant $tenant,
        string $channel,
        string $outcome,
        string $reasonCode,
        ?Request $request = null,
        ?string $surface = null,
        ?int $httpStatus = null,
        ?array $context = null,
    ): ?TenantOperationalBlockAudit {
        try {
            return TenantOperationalBlockAudit::query()->create([
                'tenant_id' => $tenant->getKey(),
                'tenant_slug' => $tenant->slug,
                'channel' => $channel,
                'outcome' => $outcome,
                'reason_code' => $reasonCode,
                'surface' => $surface,
                'route_name' => $request?->route()?->getName(),
                'method' => $request?->getMethod(),
                'endpoint' => $request?->route()?->uri() ?: $request?->path(),
                'host' => $request?->getHost(),
                'source_ip' => $request?->ip(),
                'http_status' => $httpStatus,
                'request_id' => $request?->header('x-request-id')
                    ?: $request?->header('x-fb-trace-id')
                    ?: $request?->header('x-evolution-request-id'),
                'correlation_id' => (string) Str::uuid(),
                'context_json' => $context,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            Log::warning('Falha ao persistir tenant operational block audit.', [
                'tenant_id' => $tenant->getKey(),
                'tenant_slug' => $tenant->slug,
                'channel' => $channel,
                'outcome' => $outcome,
                'reason_code' => $reasonCode,
                'audit_error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }
}
