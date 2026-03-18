<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Auth\Models\TenantUserInvitation;
use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TenantMembership extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'is_primary',
        'permissions_json',
        'invited_at',
        'accepted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'permissions_json' => 'array',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TenantUserInvitation::class, 'tenant_membership_id');
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(TenantUserInvitation::class, 'tenant_membership_id')->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }
}
