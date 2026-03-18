<?php

namespace App\Application\DTOs;

use App\Domain\Auth\Models\UserAccessToken;

readonly class IssuedTenantAccessToken
{
    public function __construct(
        public UserAccessToken $accessToken,
        public string $plainTextToken,
    ) {}
}
