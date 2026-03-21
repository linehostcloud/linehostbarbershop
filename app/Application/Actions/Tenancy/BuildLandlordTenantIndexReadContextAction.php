<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Support\Observability\LandlordTenantIndexPerformanceTracker;

class BuildLandlordTenantIndexReadContextAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantSuspendedPressureAction $buildSuspendedPressure,
        private readonly LandlordTenantIndexPerformanceTracker $performanceTracker,
    ) {
    }

    public function execute(): LandlordTenantIndexReadContext
    {
        return $this->performanceTracker->measure('read_context_duration_ms', function (): LandlordTenantIndexReadContext {
            $tenants = $this->performanceTracker->measure('tenant_query_duration_ms', fn () => Tenant::query()
                ->with([
                    'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
                    'memberships.user' => fn ($query) => $query->orderBy('name'),
                ])
                ->latest('created_at')
                ->get());
            $tenantCount = $tenants->count();
            $this->performanceTracker->setCount('tenant_count', $tenantCount);
            $this->performanceTracker->setCount(
                'suspended_tenant_candidate_count',
                $tenants->filter(fn (Tenant $tenant): bool => (string) $tenant->status === 'suspended')->count(),
            );
            $tenantSummaries = $this->performanceTracker->measure(
                'summary_mapping_duration_ms',
                fn () => $tenants
                    ->map(fn (Tenant $tenant): array => $this->mapTenantSummary->execute($tenant))
                    ->values(),
            );
            $suspendedPressure = $this->performanceTracker->measure(
                'suspended_pressure_duration_ms',
                fn () => $this->buildSuspendedPressure->execute($tenants),
            );
            $this->performanceTracker->setCount('suspended_pressure_tenant_count', $suspendedPressure->count());

            return new LandlordTenantIndexReadContext(
                tenantSummaries: $tenantSummaries,
                suspendedPressure: $suspendedPressure,
            );
        });
    }
}
