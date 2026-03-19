<?php

namespace App\Application\Actions\Observability;

use App\Domain\Observability\Models\EventLog;
use Illuminate\Support\Str;

class RecordWhatsappSchedulerEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $result
     */
    public function execute(
        string $schedulerType,
        string $eventName,
        array $payload,
        ?string $correlationId = null,
        ?array $context = null,
        ?array $result = null,
        ?string $idempotencyKey = null,
        ?\DateTimeInterface $occurredAt = null,
    ): EventLog {
        $occurredAt ??= now();
        $correlationId ??= (string) Str::ulid();

        return EventLog::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'automation_id' => null,
                'message_id' => null,
                'aggregate_type' => 'scheduler_run',
                'aggregate_id' => $correlationId,
                'event_name' => $eventName,
                'trigger_source' => 'scheduler',
                'status' => 'processed',
                'idempotency_key' => $idempotencyKey ?: sprintf('scheduler-event:%s', (string) Str::uuid()),
                'correlation_id' => $correlationId,
                'causation_id' => $schedulerType,
                'payload_json' => $payload,
                'context_json' => array_filter(array_merge([
                    'channel' => 'whatsapp',
                    'scheduler_type' => $schedulerType,
                    'scheduler_run_id' => $correlationId,
                ], $context ?? []), static fn (mixed $value): bool => $value !== null),
                'result_json' => $result,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ],
        );
    }
}
