<?php

namespace App\Domain\Appointment\Models;

use App\Domain\Client\Models\Client;
use App\Domain\Order\Models\Order;
use App\Domain\Professional\Models\Professional;
use App\Domain\Service\Models\Service;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'professional_id',
        'primary_service_id',
        'subscription_id',
        'booked_by_user_id',
        'source',
        'status',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'confirmation_status',
        'reminder_sent_at',
        'notes',
        'cancel_reason',
        'canceled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_sent_at' => 'datetime',
            'canceled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function primaryService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'primary_service_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
