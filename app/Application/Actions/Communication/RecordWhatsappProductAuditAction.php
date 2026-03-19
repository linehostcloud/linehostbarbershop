<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class RecordWhatsappProductAuditAction
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
        Model $auditable,
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
            auditableType: $auditable::class,
            auditableId: (string) $auditable->getKey(),
            before: $before,
            after: $after,
            metadata: $metadata,
        );
    }
}
