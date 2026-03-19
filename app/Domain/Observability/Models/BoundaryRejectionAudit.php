<?php

namespace App\Domain\Observability\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoundaryRejectionAudit extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'tenant_slug',
        'actor_user_id',
        'actor_email',
        'direction',
        'endpoint',
        'route_name',
        'method',
        'host',
        'source_ip',
        'provider',
        'slot',
        'code',
        'message',
        'http_status',
        'request_id',
        'correlation_id',
        'payload_json',
        'headers_json',
        'context_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'headers_json' => 'array',
            'context_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
