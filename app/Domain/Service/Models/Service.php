<?php

namespace App\Domain\Service\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Order\Models\OrderItem;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends TenantModel
{
    use HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'name',
        'description',
        'duration_minutes',
        'price_cents',
        'cost_cents',
        'commissionable',
        'default_commission_percent',
        'requires_subscription',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'commissionable' => 'boolean',
            'default_commission_percent' => 'decimal:2',
            'requires_subscription' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'primary_service_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
