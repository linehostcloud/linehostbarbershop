<?php

namespace App\Application\Actions\Appointment;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use Carbon\CarbonImmutable;

class DetermineManualAppointmentConfirmationEligibilityAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
    ) {
    }

    /**
     * @return array{
     *     eligible:bool,
     *     reason:?string,
     *     message:string,
     *     automation:Automation,
     *     context:array<string, mixed>,
     *     latest_message:?Message
     * }
     */
    public function execute(
        Appointment $appointment,
        ?Message $latestConfirmationMessage = null,
        ?CarbonImmutable $now = null,
    ): array {
        $automation = $this->automation();
        $appointment->loadMissing(['client', 'professional', 'primaryService']);
        $now ??= CarbonImmutable::now();
        $latestConfirmationMessage ??= $this->latestConfirmationMessage($appointment, $automation);

        if (
            $appointment->starts_at === null
            || in_array((string) $appointment->status, ['canceled', 'no_show', 'completed'], true)
            || $appointment->canceled_at !== null
            || ($appointment->ends_at !== null && CarbonImmutable::instance($appointment->ends_at)->lessThanOrEqualTo($now))
        ) {
            return $this->blocked($automation, $appointment, $now, 'appointment_not_eligible');
        }

        if ($appointment->client === null) {
            return $this->blocked($automation, $appointment, $now, 'missing_client');
        }

        if (! is_string($appointment->client->phone_e164) || trim($appointment->client->phone_e164) === '') {
            return $this->blocked($automation, $appointment, $now, 'missing_phone');
        }

        if (! $appointment->client->whatsapp_opt_in) {
            return $this->blocked($automation, $appointment, $now, 'whatsapp_opt_out');
        }

        if ((string) $appointment->confirmation_status === 'confirmed') {
            return $this->blocked($automation, $appointment, $now, 'already_confirmed');
        }

        if ((string) $appointment->confirmation_status === 'declined') {
            return $this->blocked($automation, $appointment, $now, 'already_declined');
        }

        if ($latestConfirmationMessage instanceof Message && in_array((string) $latestConfirmationMessage->status, [
            'queued',
            'dispatched',
            'sent',
            'delivered',
            'read',
            'duplicate_prevented',
        ], true)) {
            return $this->blocked($automation, $appointment, $now, 'confirmation_in_progress', $latestConfirmationMessage);
        }

        if (in_array((string) $appointment->confirmation_status, ['confirm_queued', 'awaiting_customer'], true)) {
            return $this->blocked($automation, $appointment, $now, 'confirmation_in_progress', $latestConfirmationMessage);
        }

        return [
            'eligible' => true,
            'reason' => null,
            'message' => 'Pode pedir confirmação manual agora.',
            'automation' => $automation,
            'context' => $this->context($automation, $appointment, $now),
            'latest_message' => $latestConfirmationMessage,
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

    private function latestConfirmationMessage(Appointment $appointment, Automation $automation): ?Message
    {
        /** @var ?Message $message */
        $message = Message::query()
            ->where('appointment_id', $appointment->id)
            ->where('automation_id', $automation->id)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->latest('created_at')
            ->get()
            ->first(fn (Message $item): bool => $this->isConfirmationMessage($item));

        return $message;
    }

    private function isConfirmationMessage(Message $message): bool
    {
        return data_get($message->payload_json, 'product.manual_action') === 'appointment_confirmation'
            || data_get($message->payload_json, 'automation.trigger_reason') === 'manual_appointment_confirmation';
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Automation $automation, Appointment $appointment, CarbonImmutable $now): array
    {
        $context = $this->discoverCandidates->appointmentContext($automation, $appointment, $now);
        $context['confirmation'] = [
            'requested_at' => $now->toIso8601String(),
            'requested_at_local' => $now->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            'mode' => 'manual',
        ];

        return $context;
    }

    /**
     * @return array{
     *     eligible:bool,
     *     reason:string,
     *     message:string,
     *     automation:Automation,
     *     context:array<string, mixed>,
     *     latest_message:?Message
     * }
     */
    private function blocked(
        Automation $automation,
        Appointment $appointment,
        CarbonImmutable $now,
        string $reason,
        ?Message $latestMessage = null,
    ): array {
        return [
            'eligible' => false,
            'reason' => $reason,
            'message' => $this->blockMessage($reason),
            'automation' => $automation,
            'context' => $this->context($automation, $appointment, $now),
            'latest_message' => $latestMessage,
        ];
    }

    private function blockMessage(string $reason): string
    {
        return match ($reason) {
            'appointment_not_eligible' => 'Esse agendamento não está apto para confirmação agora.',
            'missing_client' => 'O agendamento ainda não tem cliente vinculado.',
            'missing_phone' => 'O cliente ainda não possui telefone válido.',
            'whatsapp_opt_out' => 'O cliente não autorizou contato por WhatsApp.',
            'already_confirmed' => 'Esse agendamento já está confirmado.',
            'already_declined' => 'O cliente já informou ausência para esse agendamento.',
            'confirmation_in_progress' => 'Já existe uma confirmação em andamento para esse agendamento.',
            default => 'Esse agendamento não pode receber confirmação manual agora.',
        };
    }
}
