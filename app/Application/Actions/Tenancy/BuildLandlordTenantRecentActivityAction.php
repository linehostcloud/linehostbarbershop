<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;

class BuildLandlordTenantRecentActivityAction
{
    private const DEFAULT_LIMIT = 6;

    /**
     * @return list<array{
     *     id:string,
     *     action:string,
     *     label:string,
     *     detail:string,
     *     occurred_at:string|null,
     *     actor:array{name:string|null,email:string|null,label:string}
     * }>
     */
    public function execute(Tenant $tenant, int $limit = self::DEFAULT_LIMIT): array
    {
        return AuditLog::query()
            ->with('actor')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $auditLog): array => [
                'id' => $auditLog->id,
                'action' => $auditLog->action,
                'label' => $this->label($auditLog),
                'detail' => $this->detail($auditLog),
                'occurred_at' => $auditLog->created_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
                'actor' => [
                    'name' => $auditLog->actor?->name,
                    'email' => $auditLog->actor?->email,
                    'label' => $auditLog->actor?->name
                        ?: $auditLog->actor?->email
                        ?: 'Operação interna',
                ],
            ])
            ->values()
            ->all();
    }

    private function label(AuditLog $auditLog): string
    {
        return match ($auditLog->action) {
            'landlord_tenant.provisioned_via_web' => 'Tenant provisionado',
            'landlord_tenant.schema_sync_requested' => 'Schema sincronizado',
            'landlord_tenant.default_automations_ensured' => 'Automações default garantidas',
            'landlord_tenant.basics_updated' => 'Dados básicos atualizados',
            'landlord_tenant.domain_added' => 'Domínio adicionado',
            'landlord_tenant.primary_domain_updated' => 'Domínio principal atualizado',
            'landlord_tenant.status_changed' => 'Status do tenant atualizado',
            'landlord_tenant.onboarding_stage_transitioned' => 'Onboarding atualizado',
            default => 'Evento administrativo',
        };
    }

    private function detail(AuditLog $auditLog): string
    {
        /** @var array<string, mixed> $after */
        $after = $auditLog->after_json ?? [];
        /** @var array<string, mixed> $metadata */
        $metadata = $auditLog->metadata_json ?? [];

        return match ($auditLog->action) {
            'landlord_tenant.provisioned_via_web' => $this->provisioningDetail($after, $metadata),
            'landlord_tenant.schema_sync_requested' => $this->schemaSyncDetail($after),
            'landlord_tenant.default_automations_ensured' => $this->automationDetail($after),
            'landlord_tenant.basics_updated' => $this->basicsDetail($after, $metadata),
            'landlord_tenant.domain_added' => $this->domainAddedDetail($after),
            'landlord_tenant.primary_domain_updated' => $this->primaryDomainDetail($after),
            'landlord_tenant.status_changed' => $this->statusChangedDetail($after, $metadata),
            'landlord_tenant.onboarding_stage_transitioned' => $this->onboardingStageDetail($after, $metadata),
            default => 'Evento administrativo registrado para este tenant.',
        };
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    private function provisioningDetail(array $after, array $metadata): string
    {
        $parts = [];

        if (filled($after['slug'] ?? null)) {
            $parts[] = sprintf('Slug %s.', $after['slug']);
        }

        if (filled($after['domain'] ?? null)) {
            $parts[] = sprintf('Domínio principal %s.', $after['domain']);
        }

        if (filled($metadata['owner_email'] ?? null)) {
            $parts[] = sprintf('Owner %s.', $metadata['owner_email']);
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'Tenant provisionado pelo painel landlord.';
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function schemaSyncDetail(array $after): string
    {
        if (filled($after['database_name'] ?? null)) {
            return sprintf('Sincronização do schema solicitada para %s.', $after['database_name']);
        }

        return 'Sincronização do schema solicitada para o tenant.';
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function automationDetail(array $after): string
    {
        $count = $after['automation_count'] ?? null;

        if (is_numeric($count)) {
            return sprintf('%d automações default garantidas.', (int) $count);
        }

        return 'Automações default garantidas para o tenant.';
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    private function basicsDetail(array $after, array $metadata): string
    {
        $fields = $metadata['changed_fields'] ?? array_keys($after);

        if (! is_array($fields) || $fields === []) {
            return 'Dados básicos do tenant atualizados.';
        }

        $labels = array_map(
            fn (mixed $field): string => $this->fieldLabel((string) $field),
            array_values($fields),
        );

        return sprintf('Campos atualizados: %s.', implode(', ', $labels));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function domainAddedDetail(array $after): string
    {
        $domain = (string) ($after['domain'] ?? '');

        if ($domain === '') {
            return 'Novo domínio adicionado ao tenant.';
        }

        return (bool) ($after['is_primary'] ?? false)
            ? sprintf('Domínio %s adicionado como principal.', $domain)
            : sprintf('Domínio %s adicionado ao tenant.', $domain);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function primaryDomainDetail(array $after): string
    {
        $domain = (string) ($after['primary_domain'] ?? '');

        return $domain !== ''
            ? sprintf('Domínio principal definido como %s.', $domain)
            : 'Domínio principal atualizado para o tenant.';
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    private function statusChangedDetail(array $after, array $metadata): string
    {
        $to = (string) ($after['status'] ?? ($metadata['to'] ?? ''));
        $from = (string) ($metadata['from'] ?? '');
        $reason = rtrim(trim((string) ($metadata['reason'] ?? '')), '.');
        $parts = [];

        if ($from !== '' && $to !== '') {
            $parts[] = sprintf('Status alterado de %s para %s.', $this->labelValue($from), $this->labelValue($to));
        } elseif ($to !== '') {
            $parts[] = sprintf('Status alterado para %s.', $this->labelValue($to));
        }

        if ($reason !== '') {
            $parts[] = sprintf('Motivo: %s.', $reason);
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'Status do tenant atualizado.';
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    private function onboardingStageDetail(array $after, array $metadata): string
    {
        $to = (string) ($after['onboarding_stage'] ?? ($metadata['to'] ?? ''));
        $from = (string) ($metadata['from'] ?? '');
        $reason = rtrim(trim((string) ($metadata['reason'] ?? '')), '.');
        $parts = [];

        if ($from !== '' && $to !== '') {
            $parts[] = sprintf('Onboarding alterado de %s para %s.', $this->labelValue($from), $this->labelValue($to));
        } elseif ($to !== '') {
            $parts[] = sprintf('Onboarding alterado para %s.', $this->labelValue($to));
        }

        if ($reason !== '') {
            $parts[] = sprintf('Motivo: %s.', $reason);
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'Onboarding do tenant atualizado.';
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'trade_name' => 'Nome fantasia',
            'legal_name' => 'Razão social',
            'timezone' => 'Timezone',
            'currency' => 'Moeda',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private function labelValue(string $value): string
    {
        return match ($value) {
            'active' => 'Ativo',
            'trial' => 'Trial',
            'suspended' => 'Suspenso',
            'created' => 'Criado',
            'provisioned' => 'Provisionado',
            'completed' => 'Concluído',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }
}
