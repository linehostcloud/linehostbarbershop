<?php

namespace App\Domain\Finance\Models;

use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'opened_by_user_id',
        'closed_by_user_id',
        'status',
        'opening_balance_cents',
        'expected_balance_cents',
        'counted_cash_cents',
        'difference_cents',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
