<?php

namespace App\Application\Actions\Tenancy;

use App\Support\Observability\LandlordTenantIndexPerformanceTracker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;

class BuildLandlordTenantIndexDataAction
{
    public function __construct(
        private readonly BuildLandlordTenantIndexReadContextAction $buildReadContext,
        private readonly LandlordTenantIndexPerformanceTracker $performanceTracker,
    ) {
    }

    /**
     * @param  array{
     *     status?:string,
     *     onboarding_stage?:string,
     *     provisioning?:string,
     *     pressure?:string
     * }  $filters
     */
    public function execute(
        array $filters = [],
        ?LandlordTenantIndexReadContext $readContext = null,
    ): LengthAwarePaginator
    {
        return $this->performanceTracker->measure('listing_data_duration_ms', function () use ($filters, $readContext): LengthAwarePaginator {
            $readContext ??= $this->buildReadContext->execute();
            $perPage = (int) config('landlord.tenants.list_per_page', 15);
            $page = max(1, (int) request()->query('page', 1));
            $filteredSummaries = $this->applyFilters(
                $readContext->tenantSummaries,
                $readContext->suspendedPressure,
                $filters,
            );
            $this->performanceTracker->setCount('filtered_tenant_count', $filteredSummaries->count());
            $paginator = new LaravelLengthAwarePaginator(
                items: $filteredSummaries->forPage($page, $perPage)->values(),
                total: $filteredSummaries->count(),
                perPage: $perPage,
                currentPage: $page,
                options: [
                    'path' => request()->url(),
                    'pageName' => 'page',
                ],
            );

            return $paginator->withQueryString();
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tenantSummaries
     * @param  Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     total_blocks:int,
     *     affected_channels_count:int,
     *     last_blocked_at:string|null,
     *     channels:list<string>
     * }>  $suspendedPressure
     * @param  array{
     *     status?:string,
     *     onboarding_stage?:string,
     *     provisioning?:string,
     *     pressure?:string
     * }  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(
        Collection $tenantSummaries,
        Collection $suspendedPressure,
        array $filters,
    ): Collection {
        $status = (string) ($filters['status'] ?? '');
        $onboardingStage = (string) ($filters['onboarding_stage'] ?? '');
        $provisioning = (string) ($filters['provisioning'] ?? '');
        $pressure = (string) ($filters['pressure'] ?? '');

        $pressureTenantIds = $pressure === ResolveLandlordTenantIndexFiltersAction::PRESSURE_SUSPENDED_RECENT
            ? $suspendedPressure->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()
            : [];

        return $tenantSummaries
            ->when(
                $status !== '',
                fn (Collection $collection): Collection => $collection->filter(
                    fn (array $tenant): bool => data_get($tenant, 'status.code') === $status,
                ),
            )
            ->when(
                $onboardingStage !== '',
                fn (Collection $collection): Collection => $collection->filter(
                    fn (array $tenant): bool => data_get($tenant, 'onboarding_stage.code') === $onboardingStage,
                ),
            )
            ->when(
                $provisioning !== '',
                fn (Collection $collection): Collection => $collection->filter(
                    fn (array $tenant): bool => $this->matchesProvisioningFilter($tenant, $provisioning),
                ),
            )
            ->when(
                $pressure === ResolveLandlordTenantIndexFiltersAction::PRESSURE_SUSPENDED_RECENT,
                fn (Collection $collection): Collection => $collection->filter(
                    fn (array $tenant): bool => in_array((string) data_get($tenant, 'id'), $pressureTenantIds, true),
                ),
            )
            ->values();
    }

    /**
     * @param  array<string, mixed>  $tenant
     */
    private function matchesProvisioningFilter(array $tenant, string $provisioning): bool
    {
        $tenantProvisioning = (string) data_get($tenant, 'provisioning.code');

        if ($provisioning === ResolveLandlordTenantIndexFiltersAction::PROVISIONING_PENDING) {
            return $tenantProvisioning !== 'provisioned';
        }

        return $tenantProvisioning === $provisioning;
    }
}
