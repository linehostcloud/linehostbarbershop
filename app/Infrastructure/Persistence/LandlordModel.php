<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

abstract class LandlordModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.landlord_connection', 'landlord');
    }
}
