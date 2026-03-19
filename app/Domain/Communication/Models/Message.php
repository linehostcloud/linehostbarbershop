<?php

namespace App\Domain\Communication\Models;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'campaign_id',
        'appointment_id',
        'automation_id',
        'direction',
        'channel',
        'provider',
        'external_message_id',
        'deduplication_key',
        'thread_key',
        'type',
        'status',
        'body_text',
        'payload_json',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class);
    }

    public function outboxEvents(): HasMany
    {
        return $this->hasMany(OutboxEvent::class);
    }

    public function integrationAttempts(): HasMany
    {
        return $this->hasMany(IntegrationAttempt::class);
    }
}
