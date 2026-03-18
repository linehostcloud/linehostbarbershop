<?php

namespace App\Domain\Order\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Client\Models\Client;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Professional\Models\Professional;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'appointment_id',
        'primary_professional_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'origin',
        'status',
        'subtotal_cents',
        'discount_cents',
        'fee_cents',
        'total_cents',
        'amount_paid_cents',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function primaryProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'primary_professional_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
