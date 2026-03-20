<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;

class MapLandlordTenantSummaryAction
{
    public function __construct(
        private readonly DetermineLandlordTenantProvisioningStatusAction $determineProvisioningStatus,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        $primaryDomain = $tenant->domains->firstWhere('is_primary', true);
        $ownerMembership = $tenant->memberships
            ->filter(fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive())
            ->sortByDesc(fn (TenantMembership $membership) => $membership->is_primary)
            ->first();

        return [
            'id' => $tenant->getKey(),
            'trade_name' => $tenant->trade_name,
            'legal_name' => $tenant->legal_name,
            'slug' => $tenant->slug,
            'status' => $this->accountStatus($tenant),
            'onboarding_stage' => $this->onboardingStage($tenant),
            'primary_domain' => $primaryDomain?->domain,
            'ssl_status' => $primaryDomain?->ssl_status,
            'owner' => [
                'name' => $ownerMembership?->user?->name,
                'email' => $ownerMembership?->user?->email,
            ],
            'created_at' => $tenant->created_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            'provisioning' => $this->determineProvisioningStatus->execute($tenant),
        ];
    }

    /**
     * @return array{code:string,label:string}
     */
    private function accountStatus(Tenant $tenant): array
    {
        return match ($tenant->status) {
            'active' => ['code' => 'active', 'label' => 'Ativo'],
            'trial' => ['code' => 'trial', 'label' => 'Trial'],
            'suspended' => ['code' => 'suspended', 'label' => 'Suspenso'],
            default => ['code' => (string) $tenant->status, 'label' => ucfirst((string) $tenant->status)],
        };
    }

    /**
     * @return array{code:string,label:string}
     */
    private function onboardingStage(Tenant $tenant): array
    {
        return match ($tenant->onboarding_stage) {
            'provisioned' => ['code' => 'provisioned', 'label' => 'Provisionado'],
            'completed' => ['code' => 'completed', 'label' => 'Concluído'],
            'created' => ['code' => 'created', 'label' => 'Criado'],
            default => ['code' => (string) $tenant->onboarding_stage, 'label' => ucfirst((string) $tenant->onboarding_stage)],
        };
    }
}
