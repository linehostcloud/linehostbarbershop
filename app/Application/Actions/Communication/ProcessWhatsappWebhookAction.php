<?php

namespace App\Application\Actions\Communication;

use App\Domain\Client\Models\Client;
use App\Domain\Communication\Data\InboundWhatsappMessageData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Data\ProviderStatusUpdateData;
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ProcessWhatsappWebhookAction
{
    public function __construct(
        private readonly TenantWhatsappProviderResolver $providerResolver,
        private readonly WhatsappPayloadSanitizer $sanitizer,
        private readonly ApplyWhatsappStatusUpdateAction $applyStatusUpdate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(OutboxEvent $outboxEvent): array
    {
        $payload = $outboxEvent->payload_json;
        $context = $outboxEvent->context_json ?? [];
        $provider = (string) ($context['provider'] ?? data_get($payload, 'provider') ?? 'unknown');
        $now = now();
        $resolvedProvider = $this->providerResolver->resolveForWebhook($provider);
        $webhook = new ReceivedWhatsappWebhookData(
            provider: $provider,
            headers: is_array($context['headers'] ?? null) ? $context['headers'] : [],
            payload: is_array($payload) ? $payload : [],
            rawBody: (string) ($context['raw_body'] ?? json_encode($payload)),
            receivedAt: CarbonImmutable::instance($now),
        );
        $normalizedWebhook = $resolvedProvider->provider->normalizeWebhook($webhook, $resolvedProvider->configuration);
        $firstStatusUpdate = $normalizedWebhook->statusUpdates[0] ?? null;

        $attempt = IntegrationAttempt::query()->firstOrCreate([
            'idempotency_key' => sprintf('whatsapp-webhook:%s:%d', $outboxEvent->id, $outboxEvent->attempt_count),
        ], [
            'event_log_id' => $outboxEvent->event_log_id,
            'outbox_event_id' => $outboxEvent->id,
            'channel' => 'whatsapp',
            'provider' => $provider,
            'operation' => 'receive_webhook',
            'direction' => 'inbound',
            'status' => 'processing',
            'attempt_count' => $outboxEvent->attempt_count,
            'max_attempts' => $outboxEvent->max_attempts,
            'last_attempt_at' => $now,
            'failure_reason' => null,
            'request_payload_json' => $payload,
            'sanitized_payload_json' => $this->sanitizer->sanitize(is_array($payload) ? $payload : []),
        ]);

        $matchedMessage = null;
        $createdInboundMessage = null;

        foreach ($normalizedWebhook->statusUpdates as $statusUpdate) {
            $matchedMessage = $this->applyStatusUpdateToExistingMessage($statusUpdate) ?: $matchedMessage;
            $attempt->message_id = $attempt->message_id ?: $matchedMessage?->id;
        }

        foreach ($normalizedWebhook->inboundMessages as $inboundMessage) {
            $createdInboundMessage = $this->upsertInboundMessage($provider, $inboundMessage);
            $attempt->message_id = $attempt->message_id ?: $createdInboundMessage->id;
        }

        $attempt->forceFill([
            'status' => 'succeeded',
            'external_reference' => $matchedMessage?->external_message_id ?? $createdInboundMessage?->external_message_id,
            'provider_message_id' => $matchedMessage?->external_message_id ?? $createdInboundMessage?->external_message_id,
            'provider_status' => $firstStatusUpdate?->providerStatus,
            'provider_request_id' => $normalizedWebhook->requestId,
            'normalized_status' => $firstStatusUpdate?->normalizedStatus->value ?? ($createdInboundMessage !== null ? WhatsappMessageStatus::InboundProcessed->value : null),
            'completed_at' => $now,
            'response_payload_json' => [
                'event_type' => $normalizedWebhook->eventType,
                'matched_message_id' => $matchedMessage?->id,
                'created_message_id' => $createdInboundMessage?->id,
                'status_updates' => count($normalizedWebhook->statusUpdates),
                'inbound_messages' => count($normalizedWebhook->inboundMessages),
                'ignored_provider_statuses' => $normalizedWebhook->ignoredProviderStatuses,
            ],
        ])->save();

        return [
            'provider' => $provider,
            'event_type' => $normalizedWebhook->eventType,
            'external_message_id' => $matchedMessage?->external_message_id ?? $createdInboundMessage?->external_message_id,
            'matched_message_id' => $matchedMessage?->id,
            'created_message_id' => $createdInboundMessage?->id,
            'integration_attempt_id' => $attempt->id,
        ];
    }

    private function applyStatusUpdateToExistingMessage(ProviderStatusUpdateData $statusUpdate): ?Message
    {
        $message = Message::query()
            ->where('external_message_id', $statusUpdate->providerMessageId)
            ->latest()
            ->first();

        if ($message === null) {
            return null;
        }

        return $this->applyStatusUpdate->execute(
            message: $message,
            incomingStatus: $statusUpdate->normalizedStatus,
            error: $statusUpdate->error,
            providerMessageId: $statusUpdate->providerMessageId,
            occurredAt: $statusUpdate->occurredAt,
        );
    }

    private function upsertInboundMessage(string $provider, InboundWhatsappMessageData $inboundMessage): Message
    {
        $message = Message::query()
            ->where('direction', 'inbound')
            ->where('provider', $provider)
            ->where('external_message_id', $inboundMessage->providerMessageId)
            ->first();

        if ($message === null) {
            $message = Message::query()->create([
                'client_id' => $inboundMessage->phoneE164 !== null
                    ? Client::query()->where('phone_e164', $inboundMessage->phoneE164)->value('id')
                    : null,
                'campaign_id' => null,
                'appointment_id' => null,
                'automation_id' => null,
                'direction' => 'inbound',
                'channel' => 'whatsapp',
                'provider' => $provider,
                'external_message_id' => $inboundMessage->providerMessageId,
                'thread_key' => $inboundMessage->threadKey !== '' ? $inboundMessage->threadKey : (string) Str::ulid(),
                'type' => $inboundMessage->type,
                'status' => WhatsappMessageStatus::InboundReceived->value,
                'body_text' => $inboundMessage->bodyText,
                'payload_json' => $inboundMessage->payload,
            ]);
        }

        return $this->applyStatusUpdate->execute(
            message: $message,
            incomingStatus: WhatsappMessageStatus::InboundProcessed,
            providerMessageId: $inboundMessage->providerMessageId,
            occurredAt: $inboundMessage->occurredAt,
        );
    }
}
