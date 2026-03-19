<?php

namespace App\Application\Actions\Observability;

use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use Illuminate\Support\Str;

class RecordWhatsappPipelineEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $result
     */
    public function execute(
        OutboxEvent $outboxEvent,
        string $eventName,
        array $payload,
        ?array $context = null,
        ?array $result = null,
        ?string $idempotencyKey = null,
        ?\DateTimeInterface $occurredAt = null,
    ): EventLog {
        $parentEventLog = $outboxEvent->eventLog;
        $occurredAt ??= now();

        return EventLog::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'automation_id' => null,
                'message_id' => $outboxEvent->message_id,
                'aggregate_type' => 'message',
                'aggregate_id' => $outboxEvent->message_id,
                'event_name' => $eventName,
                'trigger_source' => 'system',
                'status' => 'processed',
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
                'correlation_id' => $parentEventLog?->correlation_id ?: (string) Str::uuid(),
                'causation_id' => $outboxEvent->event_log_id,
                'payload_json' => $payload,
                'context_json' => $context,
                'result_json' => $result,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ],
        );
    }
}
