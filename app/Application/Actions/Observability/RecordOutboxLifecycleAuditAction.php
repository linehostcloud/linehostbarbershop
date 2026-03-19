<?php

namespace App\Application\Actions\Observability;

use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use Illuminate\Support\Str;

class RecordOutboxLifecycleAuditAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        OutboxEvent $outboxEvent,
        string $eventName,
        array $payload,
        array $result,
        ?array $context = null,
    ): EventLog {
        $parentEventLog = $outboxEvent->eventLog;

        return EventLog::query()->create([
            'automation_id' => null,
            'message_id' => $outboxEvent->message_id,
            'aggregate_type' => 'outbox_event',
            'aggregate_id' => $outboxEvent->id,
            'event_name' => $eventName,
            'trigger_source' => 'system',
            'status' => 'processed',
            'idempotency_key' => null,
            'correlation_id' => $parentEventLog?->correlation_id ?: (string) Str::uuid(),
            'causation_id' => $outboxEvent->event_log_id,
            'payload_json' => $payload,
            'context_json' => $context,
            'result_json' => $result,
            'occurred_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
