<?php

namespace App\Domain\Client\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Order\Models\Order;
use App\Domain\Professional\Models\Professional;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends TenantModel
{
    use HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'external_code',
        'full_name',
        'phone_e164',
        'email',
        'birth_date',
        'preferred_professional_id',
        'acquisition_channel',
        'notes',
        'marketing_opt_in',
        'whatsapp_opt_in',
        'visit_count',
        'average_visit_interval_days',
        'retention_status',
        'last_visit_at',
        'inactive_since',
        'whatsapp_reactivation_snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'marketing_opt_in' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'last_visit_at' => 'datetime',
            'inactive_since' => 'datetime',
            'whatsapp_reactivation_snoozed_until' => 'datetime',
        ];
    }

    public function preferredProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'preferred_professional_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
