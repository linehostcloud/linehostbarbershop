<?php

namespace App\Application\Actions\Appointment;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\QueueManualWhatsappAutomationMessageAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class QueueManualAppointmentReminderAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
        private readonly QueueManualWhatsappAutomationMessageAction $queueManualMessage,
    ) {
    }

    /**
     * @return array{automation:Automation,message:Message,run_id:string}
     */
    public function execute(Appointment $appointment, ?string $actorUserId = null): array
    {
        $automation = $this->automation();
        $appointment->loadMissing(['client', 'professional', 'primaryService']);
        $now = CarbonImmutable::now();

        if (
            $appointment->starts_at === null
            || ($appointment->ends_at !== null && CarbonImmutable::instance($appointment->ends_at)->lessThanOrEqualTo($now))
        ) {
            throw ValidationException::withMessages([
                'appointment' => 'O horário desse agendamento já passou para um novo lembrete.',
            ]);
        }

        $candidate = $this->discoverCandidates->inspectAppointmentReminder(
            automation: $automation,
            appointment: $appointment,
            now: $now,
            respectWindow: false,
            respectAlreadySent: false,
        );

        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages([
                'appointment' => $this->manualBlockMessage($candidate->skipReason),
            ]);
        }

        $result = $this->queueManualMessage->execute(
            automation: $automation,
            targetType: 'appointment',
            targetId: (string) $appointment->id,
            triggerReason: 'manual_appointment_reminder',
            client: $appointment->client,
            appointment: $appointment,
            context: $candidate->context,
            runContext: [
                'actor_user_id' => $actorUserId,
                'appointment_id' => $appointment->id,
            ],
            messageMetadata: [
                'product' => [
                    'surface' => 'manager_relationship_panel',
                    'manual_action' => 'appointment_reminder',
                    'actor_user_id' => $actorUserId,
                ],
            ],
        );

        if (! $result['queued'] || ! $result['message'] instanceof Message) {
            throw ValidationException::withMessages([
                'appointment' => $result['failure_reason'] !== ''
                    ? $result['failure_reason']
                    : 'Não foi possível reenfileirar o lembrete agora.',
            ]);
        }

        $appointment->forceFill([
            'reminder_sent_at' => now(),
            'confirmation_status' => 'reminder_queued',
        ])->save();

        return [
            'automation' => $automation,
            'message' => $result['message'],
            'run_id' => $result['run']->id,
        ];
    }

    private function automation(): Automation
    {
        return $this->ensureDefaults->execute()
            ->firstWhere('trigger_event', WhatsappAutomationType::AppointmentReminder->value)
            ?? Automation::query()
                ->where('channel', 'whatsapp')
                ->where('trigger_event', WhatsappAutomationType::AppointmentReminder->value)
                ->firstOrFail();
    }

    private function manualBlockMessage(?string $skipReason): string
    {
        return match ($skipReason) {
            'appointment_not_eligible' => 'Esse agendamento não está elegível para receber lembrete.',
            'missing_client' => 'O agendamento não possui cliente vinculado.',
            'missing_phone' => 'O cliente ainda não possui telefone válido para WhatsApp.',
            'whatsapp_opt_out' => 'O cliente não autorizou contato por WhatsApp.',
            'cooldown_active' => 'Já existe um lembrete recente para este agendamento. Aguarde a próxima janela.',
            default => 'Esse agendamento não pode receber lembrete manual agora.',
        };
    }
}
