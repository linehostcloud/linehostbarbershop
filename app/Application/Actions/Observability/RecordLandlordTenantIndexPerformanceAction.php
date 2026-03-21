<?php

namespace App\Application\Actions\Observability;

use App\Support\Observability\LandlordTenantIndexPerformanceTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordLandlordTenantIndexPerformanceAction
{
    /**
     * @param  array<string, string>  $filters
     */
    public function execute(
        Request $request,
        array $filters,
        LandlordTenantIndexPerformanceTracker $tracker,
        ?Throwable $failure = null,
    ): void {
        $loggingEnabled = (bool) config('observability.landlord_tenants_index.performance_logging_enabled', true);

        if (! $loggingEnabled && $failure === null) {
            return;
        }

        $snapshot = $tracker->snapshot();
        $failureLimit = max(1, (int) config('observability.landlord_tenants_index.failure_detail_limit', 5));
        $context = [
            'event' => $failure === null ? 'landlord.tenants.index.read' : 'landlord.tenants.index.read_failed',
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'method' => $request->getMethod(),
            'filters' => array_filter($filters, static fn (string $value): bool => $value !== ''),
            'durations_ms' => $snapshot['durations_ms'],
            'counts' => $snapshot['counts'],
            'meta' => $snapshot['meta'],
            'failures' => array_slice($snapshot['failures'], 0, $failureLimit),
        ];

        if ($failure !== null) {
            Log::warning('Landlord tenant index read failed.', array_merge($context, [
                'exception_class' => $failure::class,
                'exception_message' => $failure->getMessage(),
            ]));

            return;
        }

        if (((int) ($snapshot['counts']['technical_failure_count'] ?? 0)) > 0) {
            Log::warning('Landlord tenant index read measured with technical failures.', $context);

            return;
        }

        Log::info('Landlord tenant index read measured.', $context);
    }
}
