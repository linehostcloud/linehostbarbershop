<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;

class BuildLandlordTenantDetailDataAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantOperationalHealthAction $buildOperationalHealth,
        private readonly BuildLandlordTenantRecentActivityAction $buildRecentActivity,
        private readonly BuildLandlordTenantStateGovernanceAction $buildStateGovernance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        $tenant->loadMissing([
            'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
            'memberships.user' => fn ($query) => $query->orderBy('name'),
        ]);

        $summary = $this->mapTenantSummary->execute($tenant);
        $ownerMembership = $tenant->memberships
            ->filter(fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive())
            ->sortByDesc(fn (TenantMembership $membership) => $membership->is_primary)
            ->first();
        $operational = $this->buildOperationalHealth->execute($tenant, $summary);

        return array_merge($summary, [
            'database_name' => $tenant->database_name,
            'timezone' => $tenant->timezone,
            'currency' => $tenant->currency,
            'plan_code' => $tenant->plan_code,
            'activated_at' => $tenant->activated_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            'domains' => $tenant->domains->map(fn ($domain): array => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'type' => $domain->type,
                'is_primary' => $domain->is_primary,
                'ssl_status' => $domain->ssl_status,
                'verified_at' => $domain->verified_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            ])->values()->all(),
            'owner' => [
                'name' => $ownerMembership?->user?->name,
                'email' => $ownerMembership?->user?->email,
                'role' => $ownerMembership?->role,
                'accepted_at' => $ownerMembership?->accepted_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            ],
            'operational' => $operational,
            'recent_activity' => $this->buildRecentActivity->execute($tenant),
            'state_governance' => $this->buildStateGovernance->execute($tenant, $summary, $operational),
        ]);
    }
}
