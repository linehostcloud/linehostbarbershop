<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;

class BuildLandlordTenantDetailDataAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantOperationalHealthAction $buildOperationalHealth,
        private readonly BuildLandlordTenantRecentActivityAction $buildRecentActivity,
        private readonly BuildLandlordTenantStateGovernanceAction $buildStateGovernance,
        private readonly BuildLandlordTenantSuspensionObservabilityAction $buildSuspensionObservability,
        private readonly LandlordTenantDetailPerformanceTracker $performanceTracker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        return $this->performanceTracker->measure('detail_data_duration_ms', function () use ($tenant): array {
            $this->performanceTracker->measure('tenant_relations_load_duration_ms', function () use ($tenant): void {
                $tenant->loadMissing([
                    'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
                    'memberships.user' => fn ($query) => $query->orderBy('name'),
                ]);
            });

            $this->performanceTracker->setCount('domain_count', $tenant->domains->count());
            $this->performanceTracker->setCount('membership_count', $tenant->memberships->count());

            $summary = $this->performanceTracker->measure(
                'summary_mapping_duration_ms',
                fn (): array => $this->mapTenantSummary->execute($tenant),
            );
            $ownerMembership = $tenant->memberships
                ->filter(fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive())
                ->sortByDesc(fn (TenantMembership $membership) => $membership->is_primary)
                ->first();
            $operational = $this->performanceTracker->measure(
                'operational_health_duration_ms',
                fn (): array => $this->buildOperationalHealth->execute($tenant, $summary),
            );
            $recentActivity = $this->performanceTracker->measure(
                'recent_activity_duration_ms',
                fn (): array => $this->buildRecentActivity->execute($tenant),
            );
            $stateGovernance = $this->performanceTracker->measure(
                'state_governance_duration_ms',
                fn (): array => $this->buildStateGovernance->execute($tenant, $summary, $operational),
            );
            $suspensionObservability = $this->performanceTracker->measure(
                'suspension_observability_duration_ms',
                fn (): array => $this->buildSuspensionObservability->execute($tenant),
            );

            $this->performanceTracker->setCount('recent_activity_count', count($recentActivity));
            $this->performanceTracker->setCount('operational_pending_count', (int) data_get($operational, 'summary.pending_count', 0));
            $this->performanceTracker->setCount('suspension_recent_block_count', count(data_get($suspensionObservability, 'recent_blocks', [])));
            $this->performanceTracker->setCount('suspension_affected_channels_count', (int) data_get($suspensionObservability, 'summary.affected_channels_count', 0));
            $this->performanceTracker->setMeta('tenant_status', (string) data_get($summary, 'status.code'));
            $this->performanceTracker->setMeta('tenant_onboarding_stage', (string) data_get($summary, 'onboarding_stage.code'));
            $this->performanceTracker->setMeta('provisioning_code', (string) data_get($summary, 'provisioning.code'));
            $this->performanceTracker->setMeta('suspension_observability_available', (bool) data_get($suspensionObservability, 'availability.available', true));

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
                'recent_activity' => $recentActivity,
                'state_governance' => $stateGovernance,
                'suspension_observability' => $suspensionObservability,
            ]);
        });
    }
}
