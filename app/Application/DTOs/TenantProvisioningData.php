<?php

namespace App\Application\DTOs;

readonly class TenantProvisioningData
{
    public function __construct(
        public string $slug,
        public string $tradeName,
        public string $legalName,
        public string $domain,
        public ?string $databaseName = null,
        public string $niche = 'barbershop',
        public string $timezone = 'America/Sao_Paulo',
        public string $currency = 'BRL',
        public string $planCode = 'starter',
        public ?string $ownerName = null,
        public ?string $ownerEmail = null,
        public ?string $ownerPassword = null,
    ) {
    }
}
