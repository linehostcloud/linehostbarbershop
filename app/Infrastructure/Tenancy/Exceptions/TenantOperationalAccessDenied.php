<?php

namespace App\Infrastructure\Tenancy\Exceptions;

use App\Domain\Tenant\Models\Tenant;
use RuntimeException;

class TenantOperationalAccessDenied extends RuntimeException
{
    public function __construct(
        public readonly Tenant $tenant,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forTenant(Tenant $tenant): self
    {
        $status = (string) $tenant->status;
        $identifier = $tenant->trade_name ?: $tenant->slug ?: $tenant->getKey();

        $message = $status === 'suspended'
            ? sprintf('O tenant "%s" esta suspenso e nao pode operar no momento.', $identifier)
            : sprintf('O tenant "%s" esta em status "%s" e nao pode operar no momento.', $identifier, $status);

        return new self($tenant, $message);
    }
}
