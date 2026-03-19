<?php

namespace App\Application\Actions\Communication;

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

        return $message->refresh();
    }
}
