<?php

namespace App\Application\Actions\Automation;

use App\Application\Actions\Communication\QueueWhatsappMessageAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use Illuminate\Support\Str;
use Throwable;

class QueueWhatsappAutomationTargetAction
{
    public function __construct(
        private readonly QueueWhatsappMessageAction $queueWhatsappMessage,
        private readonly RenderWhatsappAutomationMessageAction $renderMessage,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $messageMetadata
     * @param  array<string, mixed>  $messageDefinition
     * @return array{
     *     queued:bool,
     *     failure_reason:string,
     *     target:AutomationRunTarget,
     *     message:?Message
     * }
     */
    public function execute(
        Automation $automation,
        AutomationRun $run,
        string $targetType,
        string $targetId,
        string $triggerReason,
        ?Client $client,
        ?Appointment $appointment,
        array $context,
        array $messageMetadata = [],
        array $messageDefinition = [],
        string $triggerSource = 'automation',
    ): array {
        $target = AutomationRunTarget::query()->create([
            'id' => (string) Str::ulid(),
            'automation_run_id' => $run->id,
            'automation_id' => $automation->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'client_id' => $client?->id,
            'appointment_id' => $appointment?->id,
            'status' => 'processing',
            'trigger_reason' => $triggerReason,
            'context_json' => $this->targetContext($context),
        ]);

        try {
            $rendered = $this->renderMessage->execute($automation, $context, $messageDefinition);
            $payloadJson = array_merge($rendered['payload_json'], [
                'automation' => [
                    'type' => $automation->trigger_event,
                    'run_id' => $run->id,
                    'target_id' => $target->id,
                    'trigger_reason' => $triggerReason,
                    'target_reference' => [
                        'type' => $targetType,
                        'id' => $targetId,
                    ],
                ],
            ], $messageMetadata);

            $message = $this->queueWhatsappMessage->execute([
                'client_id' => $client?->id,
                'appointment_id' => $appointment?->id,
                'automation_id' => $automation->id,
                'provider' => $rendered['provider'],
                'type' => $rendered['type'],
                'body_text' => $rendered['body_text'],
                'payload_json' => $payloadJson,
                'trigger_source' => $triggerSource,
            ]);

            $target->forceFill([
                'message_id' => $message->id,
                'status' => 'queued',
                'skip_reason' => null,
                'failure_reason' => null,
                'cooldown_until' => now()->addHours(max(1, (int) $automation->cooldown_hours)),
            ])->save();

            return [
                'queued' => true,
                'failure_reason' => '',
                'target' => $target->fresh(),
                'message' => $message,
            ];
        } catch (Throwable $throwable) {
            $target->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            return [
                'queued' => false,
                'failure_reason' => $throwable->getMessage(),
                'target' => $target->fresh(),
                'message' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function targetContext(array $context): array
    {
        return array_filter([
            'tenant' => data_get($context, 'tenant'),
            'client' => data_get($context, 'client'),
            'appointment' => data_get($context, 'appointment'),
            'reactivation' => data_get($context, 'reactivation'),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
