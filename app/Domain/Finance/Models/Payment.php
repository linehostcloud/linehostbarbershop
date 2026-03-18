<?php

namespace App\Domain\Finance\Models;

use App\Domain\Client\Models\Client;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payable_type',
        'payable_id',
        'client_id',
        'provider',
        'gateway',
        'external_reference',
        'amount_cents',
        'currency',
        'installment_count',
        'status',
        'paid_at',
        'due_at',
        'failure_reason',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'installment_count' => 'integer',
            'paid_at' => 'datetime',
            'due_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
