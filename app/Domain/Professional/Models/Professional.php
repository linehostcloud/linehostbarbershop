<?php

namespace App\Domain\Professional\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Professional extends TenantModel
{
    use HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'display_name',
        'role',
        'commission_model',
        'commission_percent',
        'color_hex',
        'workday_calendar_json',
        'active',
        'hired_at',
        'terminated_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'workday_calendar_json' => 'array',
            'active' => 'boolean',
            'hired_at' => 'date',
            'terminated_at' => 'date',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'primary_professional_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
