<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddLandlordTenantDomainAction
{
    public function __construct(
        private readonly SyncTenantPrimaryDomainAction $syncTenantPrimaryDomain,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{domain:TenantDomain,became_primary:bool}
     */
    public function execute(Tenant $tenant, User $actor, array $input): array
    {
        $domainName = mb_strtolower(trim((string) $input['domain']));

        if ($this->isCentralDomain($domainName)) {
            throw new RuntimeException('Domínios centrais do landlord não podem ser vinculados a tenants.');
        }

        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($tenant, $actor, $input, $domainName): array {
                $existingDomains = $tenant->domains()
                    ->orderByDesc('is_primary')
                    ->orderBy('created_at')
                    ->orderBy('domain')
                    ->get();

                $existingPrimaryDomains = $existingDomains->where('is_primary', true)->values();
                $previousPrimary = $existingPrimaryDomains->first();
                $requestedPrimary = (bool) ($input['make_primary'] ?? false);

                $domain = $tenant->domains()->create([
                    'domain' => $domainName,
                    'type' => 'admin',
                    'is_primary' => false,
                    'ssl_status' => 'pending',
                    'verified_at' => null,
                ]);

                $targetPrimary = $requestedPrimary || ! ($previousPrimary instanceof TenantDomain)
                    ? $domain
                    : $previousPrimary;

                $this->syncTenantPrimaryDomain->execute($tenant, $targetPrimary);

                $domain->refresh();

                $this->recordAuditLog->execute(
                    action: 'landlord_tenant.domain_added',
                    tenant: $tenant,
                    actor: $actor,
                    auditableType: 'tenant_domain',
                    auditableId: $domain->id,
                    before: [
                        'primary_domain' => $previousPrimary?->domain,
                        'primary_domain_count' => $existingPrimaryDomains->count(),
                    ],
                    after: [
                        'domain' => $domain->domain,
                        'type' => $domain->type,
                        'is_primary' => $domain->is_primary,
                        'ssl_status' => $domain->ssl_status,
                        'primary_domain' => $targetPrimary->domain,
                    ],
                    metadata: [
                        'source' => 'landlord_web_panel',
                        'operation' => 'domain_add',
                        'requested_primary' => $requestedPrimary,
                    ],
                );

                return [
                    'domain' => $domain,
                    'became_primary' => $domain->is_primary,
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
