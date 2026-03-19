<?php

namespace App\Application\Actions\Communication;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Support\WhatsappMessageStateMachine;
use Carbon\CarbonImmutable;

class ApplyWhatsappStatusUpdateAction
{
    public function __construct(
        private readonly WhatsappMessageStateMachine $stateMachine,
    ) {
    }

    public function execute(
        Message $message,
        WhatsappMessageStatus $incomingStatus,
        ?ProviderErrorData $error = null,
        ?string $providerMessageId = null,
        ?CarbonImmutable $occurredAt = null,
    ): Message {
        $currentStatus = WhatsappMessageStatus::tryFrom((string) $message->status);
        $decision = $this->stateMachine->decide(
            direction: $message->direction === 'inbound' ? 'inbound' : 'outbound',
            currentStatus: $currentStatus,
            incomingStatus: $incomingStatus,
            retryableFailure: $incomingStatus === WhatsappMessageStatus::Failed && $error?->retryable === true,
        );

        if (! $decision->shouldApply) {
            return $message;
        }

        $occurredAt ??= CarbonImmutable::now();

        $payload = $message->payload_json ?? [];
        $payload['last_provider_status'] = $incomingStatus->value;

        $attributes = [
            'status' => $incomingStatus->value,
            'payload_json' => $payload,
        ];

        if ($providerMessageId !== null && $providerMessageId !== '') {
            $attributes['external_message_id'] = $providerMessageId;
        }

        if (in_array($incomingStatus, [WhatsappMessageStatus::Dispatched, WhatsappMessageStatus::Sent], true) && $message->sent_at === null) {
            $attributes['sent_at'] = $occurredAt;
        }

        if ($incomingStatus === WhatsappMessageStatus::Delivered) {
            $attributes['delivered_at'] = $occurredAt;
        }

        if ($incomingStatus === WhatsappMessageStatus::Read) {
            $attributes['read_at'] = $occurredAt;
        }

        if ($incomingStatus === WhatsappMessageStatus::Failed) {
            $attributes['failed_at'] = $occurredAt;
            $attributes['failure_reason'] = $error?->message ?? 'Falha reportada pelo provider de WhatsApp.';
        } elseif ($incomingStatus !== WhatsappMessageStatus::Queued) {
            $attributes['failed_at'] = null;
            $attributes['failure_reason'] = null;
        }

        $message->forceFill($attributes)->save();
        $this->syncAppointmentCommunicationState($message, $incomingStatus, $occurredAt);

        return $message->refresh();
    }

    private function syncAppointmentCommunicationState(
        Message $message,
        WhatsappMessageStatus $incomingStatus,
        CarbonImmutable $occurredAt,
    ): void {
        if ($message->direction !== 'outbound' || $message->appointment_id === null) {
            return;
        }

        $manualAction = data_get($message->payload_json, 'product.manual_action');

        /** @var Appointment|null $appointment */
        $appointment = $message->appointment()->first();

        if (! $appointment instanceof Appointment) {
            return;
        }

        if ($manualAction === 'appointment_confirmation') {
            $this->syncAppointmentConfirmationState($appointment, $incomingStatus);

            return;
        }

        $automationType = data_get($message->payload_json, 'automation.type');

        if (! is_string($automationType) || $automationType === '') {
            $message->loadMissing('automation');
            $automationType = $message->automation?->trigger_event;
        }

        if ($automationType !== 'appointment_reminder') {
            return;
        }

        $updates = [];

        if (in_array($incomingStatus, [
            WhatsappMessageStatus::Dispatched,
            WhatsappMessageStatus::Sent,
            WhatsappMessageStatus::Delivered,
            WhatsappMessageStatus::Read,
        ], true)) {
            if ($appointment->reminder_sent_at === null) {
                $updates['reminder_sent_at'] = $occurredAt;
            }

            if (in_array((string) $appointment->confirmation_status, ['', 'not_sent', 'reminder_queued'], true)) {
                $updates['confirmation_status'] = 'awaiting_customer';
            }
        }

        if ($updates === []) {
            return;
        }

        $appointment->forceFill($updates)->save();
    }

    private function syncAppointmentConfirmationState(
        Appointment $appointment,
        WhatsappMessageStatus $incomingStatus,
    ): void {
        $currentStatus = (string) $appointment->confirmation_status;
        $updates = [];

        if (in_array($incomingStatus, [
            WhatsappMessageStatus::Dispatched,
            WhatsappMessageStatus::Sent,
            WhatsappMessageStatus::Delivered,
            WhatsappMessageStatus::Read,
        ], true) && in_array($currentStatus, ['not_sent', 'confirm_queued', 'confirm_failed', 'manual_confirmation_requested'], true)) {
            $updates['confirmation_status'] = 'awaiting_customer';
        }

        if (
            $incomingStatus === WhatsappMessageStatus::Failed
            && in_array($currentStatus, ['not_sent', 'confirm_queued', 'awaiting_customer', 'confirm_failed', 'manual_confirmation_requested'], true)
        ) {
            $updates['confirmation_status'] = 'confirm_failed';
        }

        if ($updates === []) {
            return;
        }

        $appointment->forceFill($updates)->save();
    }
}
