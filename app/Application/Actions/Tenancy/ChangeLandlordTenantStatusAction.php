<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChangeLandlordTenantStatusAction
{
    public function __construct(
        private readonly BuildLandlordTenantStateGovernanceAction $buildStateGovernance,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:string,label:string}
     */
    public function execute(Tenant $tenant, User $actor, array $input): array
    {
        $targetStatus = (string) $input['status'];
        $reason = trim((string) $input['status_reason']);
        $transition = $this->buildStateGovernance->findStatusTransition($tenant, $targetStatus);

        if ($transition === null) {
            $governance = $this->buildStateGovernance->execute($tenant);
            $available = collect(data_get($governance, 'status.available', []));

            throw new RuntimeException(
                $available->isEmpty()
                    ? (string) data_get($governance, 'status.unavailable_reason', 'A transição de status solicitada não é permitida para o tenant.')
                    : 'A transição de status solicitada não é permitida para o estado atual do tenant.',
            );
        }

        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($tenant, $actor, $targetStatus, $reason): array {
                $before = [
                    'status' => $tenant->status,
                    'activated_at' => $tenant->activated_at?->toIso8601String(),
                    'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                ];

                $attributes = match ($targetStatus) {
                    'active' => [
                        'status' => 'active',
                        'activated_at' => $tenant->activated_at ?? now(),
                        'suspended_at' => null,
                    ],
                    'suspended' => [
                        'status' => 'suspended',
                        'suspended_at' => now(),
                    ],
                    default => throw new RuntimeException('O status informado não é suportado para transição administrativa.'),
                };

                $tenant->forceFill($attributes);
                $tenant->save();

                $this->recordAuditLog->execute(
                    action: 'landlord_tenant.status_changed',
                    tenant: $tenant,
                    actor: $actor,
                    auditableType: 'tenant',
                    auditableId: $tenant->id,
                    before: $before,
                    after: [
                        'status' => $tenant->status,
                        'activated_at' => $tenant->activated_at?->toIso8601String(),
                        'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                    ],
                    metadata: [
                        'source' => 'landlord_web_panel',
                        'reason' => $reason,
                        'from' => $before['status'],
                        'to' => $tenant->status,
                    ],
                );

                return [
                    'status' => $tenant->status,
                    'label' => $this->statusLabel($tenant->status),
                ];
            }, 3);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Ativo',
            'trial' => 'Trial',
            'suspended' => 'Suspenso',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
