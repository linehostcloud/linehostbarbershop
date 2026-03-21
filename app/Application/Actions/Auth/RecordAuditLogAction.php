<?php

namespace App\Application\Actions\Auth;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

class RecordAuditLogAction
{
    /**
     * Use esta trilha para mudancas administrativas landlord com actor e before/after.
     * Bloqueios operacionais de runtime e rejeicoes de boundary usam trilhas dedicadas.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        string $action,
        Tenant $tenant,
        ?User $actor = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor?->id,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'action' => $action,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata,
        ]);
    }
}
