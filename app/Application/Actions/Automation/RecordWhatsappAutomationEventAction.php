<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Observability\Models\EventLog;
use Illuminate\Support\Str;

class RecordWhatsappAutomationEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $result
     */
    public function execute(
        Automation $automation,
        AutomationRun $run,
        string $eventName,
        array $payload,
        ?array $context = null,
        ?array $result = null,
        ?string $messageId = null,
        ?string $idempotencyKey = null,
        ?\DateTimeInterface $occurredAt = null,
    ): EventLog {
        $occurredAt ??= now();

        return EventLog::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'automation_id' => $automation->id,
                'message_id' => $messageId,
                'aggregate_type' => 'automation_run',
                'aggregate_id' => $run->id,
                'event_name' => $eventName,
                'trigger_source' => 'system',
                'status' => 'processed',
                'idempotency_key' => $idempotencyKey ?: sprintf('automation-event:%s', (string) Str::uuid()),
                'correlation_id' => $run->id,
                'causation_id' => $automation->id,
                'payload_json' => $payload,
                'context_json' => array_filter(array_merge([
                    'channel' => 'whatsapp',
                    'automation_type' => $automation->trigger_event,
                    'automation_run_id' => $run->id,
                ], $context ?? []), static fn (mixed $value): bool => $value !== null),
                'result_json' => $result,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ],
        );
    }
}
