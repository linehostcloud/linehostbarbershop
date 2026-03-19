<?php

namespace App\Application\DTOs;

use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;

readonly class AuthenticatedTenantSessionResult
{
    /**
     * @param  list<string>  $grantedAbilities
     */
    public function __construct(
        public string $plainTextToken,
        public UserAccessToken $accessToken,
        public User $user,
        public TenantMembership $membership,
        public array $grantedAbilities,
    ) {
    }
}
