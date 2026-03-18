<?php

namespace App\Domain\Auth\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessToken extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tenant_id',
        'name',
        'token_hash',
        'abilities_json',
        'last_used_at',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities_json' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function canAccess(string $ability): bool
    {
        $abilities = $this->abilities_json ?? ['*'];

        foreach ($abilities as $grantedAbility) {
            if (
                $grantedAbility === '*'
                || $grantedAbility === $ability
                || (str_ends_with($grantedAbility, '.*') && str_starts_with($ability, substr($grantedAbility, 0, -1)))
            ) {
                return true;
            }
        }

        return false;
    }
}
