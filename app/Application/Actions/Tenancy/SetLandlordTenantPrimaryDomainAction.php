<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SetLandlordTenantPrimaryDomainAction
{
    public function __construct(
        private readonly SyncTenantPrimaryDomainAction $syncTenantPrimaryDomain,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @return array{changed:bool}
     */
    public function execute(Tenant $tenant, TenantDomain $domain, User $actor): array
    {
        if ($domain->tenant_id !== $tenant->id) {
            throw new RuntimeException('O domínio informado não pertence ao tenant selecionado.');
        }

        if ($this->isCentralDomain($domain->domain)) {
            throw new RuntimeException('Domínios centrais do landlord não podem ser definidos como domínio principal de tenant.');
        }

        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($tenant, $domain, $actor): array {
                $domains = $tenant->domains()
                    ->orderByDesc('is_primary')
                    ->orderBy('created_at')
                    ->orderBy('domain')
                    ->get();

                $currentPrimaryDomains = $domains->where('is_primary', true)->values();
                $currentPrimary = $currentPrimaryDomains->first();
                $changed = $currentPrimary?->id !== $domain->id || $currentPrimaryDomains->count() !== 1;

                $this->syncTenantPrimaryDomain->execute($tenant, $domain);

                $domain->refresh();

                if ($changed) {
                    $this->recordAuditLog->execute(
                        action: 'landlord_tenant.primary_domain_updated',
                        tenant: $tenant,
                        actor: $actor,
                        auditableType: 'tenant_domain',
                        auditableId: $domain->id,
                        before: [
                            'primary_domain' => $currentPrimary?->domain,
                            'primary_domain_count' => $currentPrimaryDomains->count(),
                        ],
                        after: [
                            'primary_domain' => $domain->domain,
                        ],
                        metadata: [
                            'source' => 'landlord_web_panel',
                            'operation' => 'primary_domain_update',
                        ],
                    );
                }

                return [
                    'changed' => $changed,
                ];
            }, 3);
    }

    private function isCentralDomain(string $domain): bool
    {
        return in_array(
            mb_strtolower(trim($domain)),
            array_values(array_unique(array_filter(array_map(
                static fn (string $configuredDomain): string => mb_strtolower(trim($configuredDomain)),
                (array) config('tenancy.central_domains', []),
            )))),
            true,
        );
    }
}
