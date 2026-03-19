<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;

class RecordWhatsappProviderAdminAuditAction
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
        WhatsappProviderConfig $configuration,
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
            auditableType: WhatsappProviderConfig::class,
            auditableId: $configuration->id,
            before: $before,
            after: $after,
            metadata: array_filter(array_merge([
                'provider' => $configuration->provider,
                'slot' => $configuration->slot,
            ], $metadata ?? []), static fn (mixed $value): bool => $value !== null),
        );
    }
}
