<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

class RunLandlordTenantSchemaSyncAction
{
    public function __construct(
        private readonly MigrateTenantSchemaAction $migrateTenantSchema,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {
    }

    public function execute(Tenant $tenant, User $actor): void
    {
        $this->migrateTenantSchema->execute($tenant);

        $this->recordAuditLog->execute(
            action: 'landlord_tenant.schema_sync_requested',
            tenant: $tenant,
            actor: $actor,
            auditableType: 'tenant',
            auditableId: $tenant->id,
            before: null,
            after: [
                'onboarding_stage' => $tenant->onboarding_stage,
                'database_name' => $tenant->database_name,
            ],
            metadata: [
                'source' => 'landlord_web_panel',
                'operation' => 'schema_sync',
            ],
        );
    }
}
