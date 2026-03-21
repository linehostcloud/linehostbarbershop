<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;

class BuildLandlordTenantDetailSnapshotPayloadAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantOperationalHealthAction $buildOperationalHealth,
        private readonly BuildLandlordTenantSuspensionObservabilityAction $buildSuspensionObservability,
    ) {}

    /**
     * @return array{
     *     provisioning:array<string, mixed>,
     *     operational:array<string, mixed>,
     *     suspension_observability:array<string, mixed>
     * }
     */
    public function execute(Tenant $tenant): array
    {
        $tenant->loadMissing([
            'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
            'memberships.user' => fn ($query) => $query->orderBy('name'),
        ]);

        $summary = $this->mapTenantSummary->execute($tenant);

        return [
            'provisioning' => $summary['provisioning'],
            'operational' => $this->buildOperationalHealth->execute($tenant, $summary),
            'suspension_observability' => $this->buildSuspensionObservability->execute($tenant),
        ];
    }
}
