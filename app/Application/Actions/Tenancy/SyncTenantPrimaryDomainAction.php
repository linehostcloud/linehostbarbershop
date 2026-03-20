<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use RuntimeException;

class SyncTenantPrimaryDomainAction
{
    public function execute(Tenant $tenant, TenantDomain $primaryDomain): void
    {
        if ($primaryDomain->tenant_id !== $tenant->id) {
            throw new RuntimeException('O domínio informado não pertence ao tenant selecionado.');
        }

        $tenant->domains()->whereKeyNot($primaryDomain->getKey())->update([
            'is_primary' => false,
        ]);

        $tenant->domains()->whereKey($primaryDomain->getKey())->update([
            'is_primary' => true,
        ]);
    }
}
