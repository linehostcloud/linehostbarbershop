<?php

namespace App\Application\Actions\Appointment;

use App\Application\Actions\Automation\QueueManualWhatsappAutomationMessageAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use Illuminate\Validation\ValidationException;

class QueueManualAppointmentConfirmationAction
{
    public function __construct(
        private readonly DetermineManualAppointmentConfirmationEligibilityAction $determineEligibility,
        private readonly QueueManualWhatsappAutomationMessageAction $queueManualMessage,
    ) {
    }

    /**
     * @return array{automation:Automation,message:Message,run_id:string}
     */
    public function execute(Appointment $appointment, ?string $actorUserId = null): array
    {
        $inspection = $this->determineEligibility->execute($appointment);

        if (! $inspection['eligible']) {
            throw ValidationException::withMessages([
                'appointment' => $inspection['message'],
            ]);
        }

        $result = $this->queueManualMessage->execute(
            automation: $inspection['automation'],
            targetType: 'appointment_confirmation',
            targetId: (string) $appointment->id,
            triggerReason: 'manual_appointment_confirmation',
            client: $appointment->client,
            appointment: $appointment,
            context: $inspection['context'],
            runContext: [
                'actor_user_id' => $actorUserId,
                'appointment_id' => $appointment->id,
                'manual_flow' => 'appointment_confirmation',
            ],
            messageMetadata: [
                'product' => [
                    'surface' => 'manager_relationship_panel',
                    'manual_action' => 'appointment_confirmation',
                    'actor_user_id' => $actorUserId,
                ],
                'confirmation' => [
                    'mode' => 'manual',
                ],
            ],
            messageDefinition: (array) config('communication.whatsapp.product_flows.appointment_confirmation.message', []),
        );

        if (! $result['queued'] || ! $result['message'] instanceof Message) {
            throw ValidationException::withMessages([
                'appointment' => $result['failure_reason'] !== ''
                    ? $result['failure_reason']
                    : 'Não foi possível solicitar a confirmação agora.',
            ]);
        }

        $appointment->forceFill([
            'confirmation_status' => 'confirm_queued',
        ])->save();

        return [
            'automation' => $inspection['automation'],
            'message' => $result['message'],
            'run_id' => $result['run']->id,
        ];
    }
}
