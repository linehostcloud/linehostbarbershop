<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Application\DTOs\TenantProvisioningResult;
use App\Models\User;

class ProvisionTenantFromLandlordPanelAction
{
    public function __construct(
        private readonly BuildTenantProvisioningDataAction $buildProvisioningData,
        private readonly ProvisionTenantAction $provisionTenant,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, array $input): TenantProvisioningResult
    {
        $data = $this->buildProvisioningData->execute($input);
        $result = $this->provisionTenant->execute($data);

        $this->recordAuditLog->execute(
            action: 'landlord_tenant.provisioned_via_web',
            tenant: $result->tenant,
            actor: $actor,
            auditableType: 'tenant',
            auditableId: $result->tenant->id,
            before: null,
            after: [
                'trade_name' => $result->tenant->trade_name,
                'slug' => $result->tenant->slug,
                'database_name' => $result->databaseName,
                'domain' => $result->domain,
                'plan_code' => $result->tenant->plan_code,
                'onboarding_stage' => $result->tenant->onboarding_stage,
            ],
            metadata: [
                'source' => 'landlord_web_panel',
                'owner_created' => $result->ownerCreated,
                'owner_email' => $result->owner?->email,
            ],
        );

        return $result;
    }
}
