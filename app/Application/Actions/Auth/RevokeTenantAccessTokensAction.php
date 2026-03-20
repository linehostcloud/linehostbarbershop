<?php

namespace App\Application\Actions\Auth;

use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Tenant\Models\Tenant;

class RevokeTenantAccessTokensAction
{
    public function execute(Tenant $tenant): int
    {
        return UserAccessToken::query()
            ->where('tenant_id', $tenant->id)
            ->delete();
    }
}
