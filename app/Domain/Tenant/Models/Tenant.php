<?php

namespace App\Domain\Tenant\Models;

use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

class Tenant extends LandlordModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    public const RUNTIME_ENABLED_STATUSES = ['active', 'trial'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_name',
        'trade_name',
        'slug',
        'niche',
        'timezone',
        'currency',
        'status',
        'onboarding_stage',
        'database_name',
        'database_host',
        'database_port',
        'database_username',
        'database_password_encrypted',
        'plan_code',
        'trial_ends_at',
        'activated_at',
        'suspended_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'database_port' => 'integer',
            'trial_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function detailSnapshot(): HasOne
    {
        return $this->hasOne(LandlordTenantDetailSnapshot::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['role', 'is_primary', 'permissions_json', 'invited_at', 'accepted_at', 'revoked_at'])
            ->withTimestamps();
    }

    public function resolveDatabasePassword(): ?string
    {
        if (blank($this->database_password_encrypted)) {
            return null;
        }

        return Crypt::decryptString($this->database_password_encrypted);
    }

    /**
     * @return list<string>
     */
    public static function runtimeEnabledStatuses(): array
    {
        return self::RUNTIME_ENABLED_STATUSES;
    }

    public function allowsOperationalRuntime(): bool
    {
        return in_array((string) $this->status, self::RUNTIME_ENABLED_STATUSES, true);
    }

    public function blocksOperationalRuntime(): bool
    {
        return ! $this->allowsOperationalRuntime();
    }
}
