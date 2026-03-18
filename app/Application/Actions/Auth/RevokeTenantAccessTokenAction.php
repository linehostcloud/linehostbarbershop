<?php

namespace App\Application\Actions\Auth;

use App\Domain\Auth\Models\UserAccessToken;

class RevokeTenantAccessTokenAction
{
    public function execute(UserAccessToken $accessToken): void
    {
        $accessToken->delete();
    }
}
