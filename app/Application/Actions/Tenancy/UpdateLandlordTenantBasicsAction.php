<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

class UpdateLandlordTenantBasicsAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{changed:bool,changed_fields:list<string>}
     */
    public function execute(Tenant $tenant, User $actor, array $input): array
    {
        $attributes = [
            'trade_name' => trim((string) $input['trade_name']),
            'legal_name' => trim((string) ($input['legal_name'] ?? '')) ?: trim((string) $input['trade_name']),
            'timezone' => trim((string) $input['timezone']),
            'currency' => mb_strtoupper(trim((string) $input['currency'])),
        ];

        $before = [];
        $after = [];

        foreach ($attributes as $field => $value) {
            if ((string) $tenant->getAttribute($field) === (string) $value) {
                continue;
            }

            $before[$field] = $tenant->getAttribute($field);
            $after[$field] = $value;
        }

        if ($after === []) {
            return [
                'changed' => false,
                'changed_fields' => [],
            ];
        }

        $tenant->forceFill($attributes);
        $tenant->save();

        $this->recordAuditLog->execute(
            action: 'landlord_tenant.basics_updated',
            tenant: $tenant,
            actor: $actor,
            auditableType: 'tenant',
            auditableId: $tenant->id,
            before: $before,
            after: $after,
            metadata: [
                'source' => 'landlord_web_panel',
                'changed_fields' => array_keys($after),
            ],
        );

        return [
            'changed' => true,
            'changed_fields' => array_keys($after),
        ];
    }
}
