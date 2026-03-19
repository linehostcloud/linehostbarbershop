<?php

namespace App\Application\Actions\Automation;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use Carbon\CarbonImmutable;

class QueueManualWhatsappAutomationMessageAction
{
    public function __construct(
        private readonly QueueWhatsappAutomationTargetAction $queueAutomationTarget,
        private readonly RecordWhatsappAutomationEventAction $recordEvent,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $runContext
     * @param  array<string, mixed>  $messageMetadata
     * @return array{
     *     queued:bool,
     *     failure_reason:string,
     *     run:AutomationRun,
     *     target:\App\Domain\Automation\Models\AutomationRunTarget,
     *     message:?Message
     * }
     */
    public function execute(
        Automation $automation,
        string $targetType,
        string $targetId,
        string $triggerReason,
        ?Client $client,
        ?Appointment $appointment,
        array $context,
        array $runContext = [],
        array $messageMetadata = [],
    ): array {
        $now = CarbonImmutable::now();
        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'automation_type' => (string) $automation->trigger_event,
            'channel' => 'whatsapp',
            'status' => 'running',
            'window_started_at' => $now,
            'window_ended_at' => $now,
            'started_at' => $now,
            'run_context_json' => array_filter(array_merge([
                'source' => 'manual_product_panel',
                'trigger_reason' => $triggerReason,
            ], $runContext), static fn (mixed $value): bool => $value !== null),
        ]);

        $result = $this->queueAutomationTarget->execute(
            automation: $automation,
            run: $run,
            targetType: $targetType,
            targetId: $targetId,
            triggerReason: $triggerReason,
            client: $client,
            appointment: $appointment,
            context: $context,
            messageMetadata: $messageMetadata,
            triggerSource: 'product_panel',
        );

        $run->forceFill([
            'status' => $result['queued'] ? 'completed' : 'failed',
            'candidates_found' => 1,
            'messages_queued' => $result['queued'] ? 1 : 0,
            'skipped_total' => 0,
            'failed_total' => $result['queued'] ? 0 : 1,
            'result_json' => [
                'trigger_reason' => $triggerReason,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'manual' => true,
            ],
            'failure_reason' => $result['queued'] ? null : $result['failure_reason'],
            'completed_at' => now(),
        ])->save();

        $this->recordEvent->execute(
            automation: $automation,
            run: $run,
            eventName: $result['queued']
                ? 'whatsapp.automation.manual_dispatch.completed'
                : 'whatsapp.automation.manual_dispatch.failed',
            payload: [
                'automation_run_id' => $run->id,
                'automation_type' => $automation->trigger_event,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'trigger_reason' => $triggerReason,
                'manual' => true,
                'failure_reason' => $result['queued'] ? null : $result['failure_reason'],
            ],
            context: [
                'source' => 'product_panel',
            ],
            result: [
                'status' => $result['queued'] ? 'completed' : 'failed',
            ],
            messageId: $result['message']?->id,
            idempotencyKey: sprintf('automation-manual-dispatch:%s', $run->id),
            occurredAt: now(),
        );

        return [
            'queued' => $result['queued'],
            'failure_reason' => $result['failure_reason'],
            'run' => $run->fresh(),
            'target' => $result['target'],
            'message' => $result['message'],
        ];
    }
}
