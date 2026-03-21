<?php

namespace App\Domain\Observability\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Persistence\LandlordModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOperationalBlockAudit extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'tenant_slug',
        'channel',
        'outcome',
        'reason_code',
        'surface',
        'route_name',
        'method',
        'endpoint',
        'host',
        'source_ip',
        'http_status',
        'request_id',
        'correlation_id',
        'context_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
