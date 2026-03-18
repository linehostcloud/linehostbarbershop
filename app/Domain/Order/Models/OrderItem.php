<?php

namespace App\Domain\Order\Models;

use App\Domain\Professional\Models\Professional;
use App\Domain\Service\Models\Service;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'service_id',
        'professional_id',
        'subscription_id',
        'type',
        'description',
        'quantity',
        'unit_price_cents',
        'total_price_cents',
        'commission_percent',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'commission_percent' => 'decimal:2',
            'metadata_json' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
