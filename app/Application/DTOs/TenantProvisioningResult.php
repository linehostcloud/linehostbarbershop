<?php

namespace App\Application\DTOs;

use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

readonly class TenantProvisioningResult
{
    public function __construct(
        public Tenant $tenant,
        public string $databaseName,
        public string $domain,
        public bool $ownerCreated,
        public ?User $owner = null,
        public ?string $temporaryPassword = null,
    ) {
    }
}
