<?php

namespace App\Application\Actions\Agent;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Observability\Models\EventLog;
use Illuminate\Support\Str;

class RecordWhatsappAgentEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $result
     */
    public function execute(
        AgentRun $run,
        string $eventName,
        array $payload,
        ?AgentInsight $insight = null,
        ?array $context = null,
        ?array $result = null,
        ?string $idempotencyKey = null,
        ?\DateTimeInterface $occurredAt = null,
    ): EventLog {
        $occurredAt ??= now();

        return EventLog::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'automation_id' => $insight?->automation_id,
                'message_id' => null,
                'aggregate_type' => $insight === null ? 'agent_run' : 'agent_insight',
                'aggregate_id' => $insight?->id ?? $run->id,
                'event_name' => $eventName,
                'trigger_source' => 'system',
                'status' => 'processed',
                'idempotency_key' => $idempotencyKey ?: sprintf('agent-event:%s', (string) Str::uuid()),
                'correlation_id' => $run->id,
                'causation_id' => $insight?->id,
                'payload_json' => $payload,
                'context_json' => array_filter(array_merge([
                    'channel' => 'whatsapp',
                    'agent_run_id' => $run->id,
                    'insight_id' => $insight?->id,
                    'insight_type' => $insight?->type,
                    'provider' => $insight?->provider,
                    'provider_slot' => $insight?->slot,
                ], $context ?? []), static fn (mixed $value): bool => $value !== null),
                'result_json' => $result,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ],
        );
    }
}
