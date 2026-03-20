<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;

class EnsureLandlordTenantDefaultAutomationsAction
{
    public function __construct(
        private readonly TenantDatabaseManager $tenantDatabaseManager,
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaultWhatsappAutomations,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {
    }

    public function execute(Tenant $tenant, User $actor): int
    {
        $this->tenantDatabaseManager->connect($tenant);

        try {
            $automations = $this->ensureDefaultWhatsappAutomations->execute();
        } finally {
            $this->tenantDatabaseManager->disconnect();
        }

        $this->recordAuditLog->execute(
            action: 'landlord_tenant.default_automations_ensured',
            tenant: $tenant,
            actor: $actor,
            auditableType: 'tenant',
            auditableId: $tenant->id,
            before: null,
            after: [
                'automation_count' => $automations->count(),
            ],
            metadata: [
                'source' => 'landlord_web_panel',
                'operation' => 'ensure_default_automations',
            ],
        );

        return $automations->count();
    }
}
