<?php

namespace App\Application\Actions\Observability;

use App\Domain\Observability\Models\EventLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordEventLogAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        string $eventName,
        string $aggregateType,
        ?string $aggregateId,
        string $triggerSource,
        array $payload,
        ?array $context = null,
        ?string $messageId = null,
        ?string $automationId = null,
        ?string $outboxEventName = null,
        ?string $topic = null,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?int $maxAttempts = null,
        ?int $retryBackoffSeconds = null,
        ?\DateTimeInterface $availableAt = null,
    ): EventLog {
        $connection = config('tenancy.tenant_connection', 'tenant');
        $eventLog = DB::connection($connection)->transaction(function () use (
            $aggregateId,
            $aggregateType,
            $automationId,
            $availableAt,
            $causationId,
            $context,
            $correlationId,
            $eventName,
            $idempotencyKey,
            $maxAttempts,
            $messageId,
            $payload,
            $retryBackoffSeconds,
            $topic,
            $triggerSource,
            $outboxEventName,
        ) {
            $eventLog = EventLog::query()->create([
                'automation_id' => $automationId,
                'message_id' => $messageId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_name' => $eventName,
                'trigger_source' => $triggerSource,
                'status' => 'recorded',
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId ?: (string) Str::uuid(),
                'causation_id' => $causationId,
                'payload_json' => $payload,
                'context_json' => $context,
                'occurred_at' => now(),
            ]);

            $eventLog->outboxEvents()->create([
                'message_id' => $messageId,
                'event_name' => $outboxEventName ?: $eventName,
                'topic' => $topic ?: ($outboxEventName ?: $eventName),
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'status' => 'pending',
                'attempt_count' => 0,
                'max_attempts' => $maxAttempts ?: (int) config('observability.outbox.default_max_attempts', 5),
                'retry_backoff_seconds' => $retryBackoffSeconds ?: (int) config('observability.outbox.default_retry_backoff_seconds', 60),
                'payload_json' => $payload,
                'context_json' => $context,
                'available_at' => $availableAt ?: now(),
            ]);

            return $eventLog;
        });

        return $eventLog->load('outboxEvents');
    }
}
