<?php

namespace App\Application\Actions\Automation;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Automation\Models\Automation;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;

class RecordWhatsappAutomationAdminAuditAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
        private readonly TenantContext $tenantContext,
        private readonly TenantAuthContext $tenantAuthContext,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        Request $request,
        string $action,
        Automation $automation,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): void {
        $tenant = $this->tenantContext->current();

        if ($tenant === null) {
            return;
        }

        $this->recordAuditLog->execute(
            action: $action,
            tenant: $tenant,
            actor: $this->tenantAuthContext->user($request),
            auditableType: Automation::class,
            auditableId: $automation->id,
            before: $before,
            after: $after,
            metadata: array_filter(array_merge([
                'automation_type' => $automation->trigger_event,
                'channel' => $automation->channel,
            ], $metadata ?? []), static fn (mixed $value): bool => $value !== null),
        );
    }
}
