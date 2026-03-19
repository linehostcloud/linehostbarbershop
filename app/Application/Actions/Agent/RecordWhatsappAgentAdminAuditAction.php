<?php

namespace App\Application\Actions\Agent;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;

class RecordWhatsappAgentAdminAuditAction
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
        AgentInsight $insight,
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
            auditableType: AgentInsight::class,
            auditableId: $insight->id,
            before: $before,
            after: $after,
            metadata: array_filter(array_merge([
                'insight_type' => $insight->type,
                'recommendation_type' => $insight->recommendation_type,
                'channel' => $insight->channel,
            ], $metadata ?? []), static fn (mixed $value): bool => $value !== null),
        );
    }
}
