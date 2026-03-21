<?php

namespace App\Application\Actions\Observability;

use App\Domain\Tenant\Models\Tenant;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordLandlordTenantDetailPerformanceAction
{
    public function execute(
        Request $request,
        Tenant $tenant,
        LandlordTenantDetailPerformanceTracker $tracker,
        ?Throwable $failure = null,
    ): void {
        $loggingEnabled = (bool) config('observability.landlord_tenants_detail.performance_logging_enabled', true);

        if (! $loggingEnabled && $failure === null) {
            return;
        }

        $snapshot = $tracker->snapshot();
        $failureLimit = max(1, (int) config('observability.landlord_tenants_detail.failure_detail_limit', 5));
        $context = [
            'event' => $failure === null ? 'landlord.tenants.show.read' : 'landlord.tenants.show.read_failed',
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'method' => $request->getMethod(),
            'tenant_id' => (string) $tenant->getKey(),
            'tenant_slug' => (string) $tenant->slug,
            'durations_ms' => $snapshot['durations_ms'],
            'counts' => $snapshot['counts'],
            'meta' => $snapshot['meta'],
            'failures' => array_slice($snapshot['failures'], 0, $failureLimit),
        ];

        if ($failure !== null) {
            Log::warning('Landlord tenant detail read failed.', array_merge($context, [
                'exception_class' => $failure::class,
                'exception_message' => $failure->getMessage(),
            ]));

            return;
        }

        if (((int) ($snapshot['counts']['technical_failure_count'] ?? 0)) > 0) {
            Log::warning('Landlord tenant detail read measured with technical failures.', $context);

            return;
        }

        Log::info('Landlord tenant detail read measured.', $context);
    }
}
