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
            'generated_at' => 'datetime',
            'last_refresh_started_at' => 'datetime',
            'last_refresh_completed_at' => 'datetime',
            'last_refresh_failed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
