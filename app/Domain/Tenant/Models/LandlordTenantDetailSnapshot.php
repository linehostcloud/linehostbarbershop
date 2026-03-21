<?php

namespace App\Domain\Tenant\Models;

use App\Infrastructure\Persistence\LandlordModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordTenantDetailSnapshot extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'refresh_status',
        'last_refresh_source',
        'last_refresh_error',
        'retry_attempt',
        'next_retry_at',
        'retry_exhausted_at',
        'payload_json',
        'generated_at',
        'last_refresh_started_at',
        'last_refresh_completed_at',
        'last_refresh_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'retry_attempt' => 'integer',
            'generated_at' => 'datetime',
            'last_refresh_started_at' => 'datetime',
            'last_refresh_completed_at' => 'datetime',
            'last_refresh_failed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'retry_exhausted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
