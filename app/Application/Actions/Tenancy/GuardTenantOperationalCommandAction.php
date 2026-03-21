<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Observability\RecordTenantOperationalBlockAuditAction;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;
use Illuminate\Support\Facades\Log;

class GuardTenantOperationalCommandAction
{
    public function __construct(
        private readonly EnsureTenantOperationalAccessAction $ensureTenantOperationalAccess,
        private readonly RecordTenantOperationalBlockAuditAction $recordOperationalBlockAudit,
    ) {}

    /**
     * Use this guard for tenant-aware runtime commands.
     * Maintenance/provisioning commands may bypass it intentionally.
     */
    public function execute(Tenant $tenant, string $commandName): bool
    {
        try {
            $this->ensureTenantOperationalAccess->execute($tenant);

            return true;
        } catch (TenantOperationalAccessDenied $exception) {
            $this->recordOperationalBlockAudit->execute(
                tenant: $tenant,
                channel: 'command',
                outcome: 'skipped',
                reasonCode: 'tenant_status_runtime_enforcement',
                surface: $commandName,
                context: [
                    'tenant_status' => $tenant->status,
                    'enforcement_policy' => 'tenant_status_runtime_enforcement',
                    'message' => $exception->getMessage(),
                ],
            );

            Log::notice('Tenant operational runtime command skipped.', [
                'tenant_id' => $tenant->getKey(),
                'tenant_slug' => $tenant->slug,
                'tenant_status' => $tenant->status,
                'operational_channel' => 'command',
                'command_name' => $commandName,
                'skip_reason' => 'tenant_status_runtime_enforcement',
            ]);

            return false;
        }
    }
}
