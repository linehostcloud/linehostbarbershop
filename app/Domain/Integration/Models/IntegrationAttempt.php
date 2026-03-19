<?php

namespace App\Domain\Integration\Models;

use App\Domain\Communication\Models\Message;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationAttempt extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'event_log_id',
        'outbox_event_id',
        'channel',
        'provider',
        'operation',
        'direction',
        'status',
        'external_reference',
        'provider_message_id',
        'provider_status',
        'provider_error_code',
        'provider_request_id',
        'http_status',
        'latency_ms',
        'retryable',
        'normalized_status',
        'normalized_error_code',
        'idempotency_key',
        'attempt_count',
        'max_attempts',
        'last_attempt_at',
        'next_retry_at',
        'completed_at',
        'failed_at',
        'failure_reason',
        'request_payload_json',
        'response_payload_json',
        'sanitized_payload_json',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'retryable' => 'boolean',
            'request_payload_json' => 'array',
            'response_payload_json' => 'array',
            'sanitized_payload_json' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function eventLog(): BelongsTo
    {
        return $this->belongsTo(EventLog::class);
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class);
    }
}
