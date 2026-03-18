<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }
}
