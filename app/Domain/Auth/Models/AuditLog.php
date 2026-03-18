<?php

namespace App\Domain\Auth\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'before_json',
        'after_json',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'metadata_json' => 'array',
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
