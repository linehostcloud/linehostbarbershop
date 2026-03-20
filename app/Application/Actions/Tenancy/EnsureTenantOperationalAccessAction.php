<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;

class EnsureTenantOperationalAccessAction
{
    public function execute(Tenant $tenant): void
    {
        if ($tenant->blocksOperationalRuntime()) {
            throw TenantOperationalAccessDenied::forTenant($tenant);
        }
    }
}
