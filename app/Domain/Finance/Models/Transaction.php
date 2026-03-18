<?php

namespace App\Domain\Finance\Models;

use App\Domain\Professional\Models\Professional;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'professional_id',
        'cash_register_session_id',
        'source_type',
        'source_id',
        'occurred_on',
        'type',
        'category',
        'description',
        'amount_cents',
        'balance_direction',
        'reconciled',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'reconciled' => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
