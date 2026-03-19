<?php

namespace App\Domain\Observability\Models;

use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboxEvent extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_log_id',
        'message_id',
        'event_name',
        'topic',
        'aggregate_type',
        'aggregate_id',
        'status',
        'attempt_count',
        'max_attempts',
        'retry_backoff_seconds',
        'payload_json',
        'context_json',
        'available_at',
        'reserved_at',
        'processed_at',
        'failed_at',
        'failure_reason',
        'reclaim_count',
        'last_reclaimed_at',
        'last_reclaim_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'context_json' => 'array',
            'available_at' => 'datetime',
            'reserved_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_reclaimed_at' => 'datetime',
        ];
    }

    public function eventLog(): BelongsTo
    {
        return $this->belongsTo(EventLog::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function integrationAttempts(): HasMany
    {
        return $this->hasMany(IntegrationAttempt::class);
    }
}
