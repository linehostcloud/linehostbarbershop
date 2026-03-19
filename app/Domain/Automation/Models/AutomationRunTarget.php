<?php

namespace App\Domain\Automation\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunTarget extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'automation_run_id',
        'automation_id',
        'target_type',
        'target_id',
        'client_id',
        'appointment_id',
        'message_id',
        'status',
        'trigger_reason',
        'skip_reason',
        'failure_reason',
        'cooldown_until',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'cooldown_until' => 'datetime',
            'context_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
