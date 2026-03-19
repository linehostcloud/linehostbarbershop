<?php

namespace App\Domain\Observability\Models;

use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventLog extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'automation_id',
        'message_id',
        'aggregate_type',
        'aggregate_id',
        'event_name',
        'trigger_source',
        'status',
        'idempotency_key',
        'correlation_id',
        'causation_id',
        'payload_json',
        'context_json',
        'result_json',
        'occurred_at',
        'processed_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'context_json' => 'array',
            'result_json' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
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
