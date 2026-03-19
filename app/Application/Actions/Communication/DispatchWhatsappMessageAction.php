<?php

namespace App\Application\Actions\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappDispatchCapabilityGuard;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use Carbon\CarbonImmutable;
use Throwable;

class DispatchWhatsappMessageAction
{
    public function __construct(
        private readonly TenantWhatsappProviderResolver $providerResolver,
        private readonly WhatsappDispatchCapabilityGuard $capabilityGuard,
        private readonly WhatsappPayloadSanitizer $sanitizer,
        private readonly ApplyWhatsappStatusUpdateAction $applyStatusUpdate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(OutboxEvent $outboxEvent): array
    {
        $messageId = $outboxEvent->message_id ?: data_get($outboxEvent->payload_json, 'message_id');
        $message = Message::query()->with('client')->findOrFail($messageId);
        $now = now();

        if (
            $message->external_message_id !== null
            && in_array($message->status, [
                WhatsappMessageStatus::Dispatched->value,
                WhatsappMessageStatus::Sent->value,
                WhatsappMessageStatus::Delivered->value,
                WhatsappMessageStatus::Read->value,
            ], true)
        ) {
            return [
                'provider' => $message->provider,
                'message_id' => $message->id,
                'external_message_id' => $message->external_message_id,
                'decision' => 'already_dispatched',
            ];
        }

        $resolvedProvider = $this->providerResolver->resolveForOutbound($message->provider);
        $provider = $resolvedProvider->configuration->provider;
        $capability = $this->capabilityForMessageType($message->type);
        $attempt = IntegrationAttempt::query()->firstOrCreate([
            'idempotency_key' => sprintf('whatsapp-dispatch:%s:%d', $outboxEvent->id, $outboxEvent->attempt_count),
        ], [
            'message_id' => $message->id,
            'event_log_id' => $outboxEvent->event_log_id,
            'outbox_event_id' => $outboxEvent->id,
            'channel' => 'whatsapp',
            'provider' => $provider,
            'operation' => 'send_message',
            'direction' => 'outbound',
            'status' => 'processing',
            'attempt_count' => $outboxEvent->attempt_count,
            'max_attempts' => $outboxEvent->max_attempts,
            'last_attempt_at' => $now,
            'next_retry_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        try {
            $outboundMessage = $this->makeOutboundMessageData($message, $outboxEvent->attempt_count);
            $this->capabilityGuard->assert($provider, $resolvedProvider->configuration, $capability);
            $requestPayload = [
                'message_id' => $message->id,
                'thread_key' => $message->thread_key,
                'type' => $message->type,
                'provider' => $provider,
                'payload' => $outboundMessage->payload,
                'to' => $outboundMessage->recipientPhoneE164,
                'body_text' => $outboundMessage->bodyText,
                'template_name' => $outboundMessage->templateName,
                'media_url' => $outboundMessage->mediaUrl,
            ];

            $attempt->forceFill([
                'request_payload_json' => $requestPayload,
                'sanitized_payload_json' => $this->sanitizer->sanitize($requestPayload),
            ])->save();

            $result = match ($message->type) {
                'text' => $resolvedProvider->provider->sendText($outboundMessage, $resolvedProvider->configuration),
                'template' => $resolvedProvider->provider->sendTemplate($outboundMessage, $resolvedProvider->configuration),
                'media' => $resolvedProvider->provider->sendMedia($outboundMessage, $resolvedProvider->configuration),
                default => throw new WhatsappProviderException(new ProviderErrorData(
                    code: WhatsappProviderErrorCode::UnsupportedFeature,
                    message: sprintf('Tipo de mensagem "%s" nao suportado para dispatch.', $message->type),
                    retryable: false,
                )),
            };
        } catch (Throwable $throwable) {
            $exception = $throwable instanceof WhatsappProviderException
                ? $throwable
                : new WhatsappProviderException(new ProviderErrorData(
                    code: WhatsappProviderErrorCode::UnknownError,
                    message: $throwable->getMessage(),
                    retryable: false,
                ), $throwable);

            $willRetry = $exception->isRetryable() && $outboxEvent->attempt_count < $outboxEvent->max_attempts;
            $nextRetryAt = $willRetry ? $now->copy()->addSeconds($outboxEvent->retry_backoff_seconds) : null;

            $attempt->forceFill([
                'status' => $willRetry ? 'retry_scheduled' : 'failed',
                'provider_error_code' => $exception->error->providerCode,
                'http_status' => $exception->error->httpStatus,
                'provider_request_id' => $exception->error->requestId,
                'retryable' => $exception->isRetryable(),
                'normalized_error_code' => $exception->error->code->value,
                'next_retry_at' => $nextRetryAt,
                'failed_at' => $willRetry ? null : $now,
                'failure_reason' => $exception->error->message,
                'response_payload_json' => $exception->error->details,
                'sanitized_payload_json' => $this->sanitizer->sanitize($exception->error->details),
            ])->save();

            $message->forceFill([
                'failure_reason' => $exception->error->message,
                'failed_at' => $willRetry ? null : $now,
            ])->save();

            if (! $willRetry) {
                $this->applyStatusUpdate->execute(
                    message: $message,
                    incomingStatus: WhatsappMessageStatus::Failed,
                    error: $exception->error,
                    providerMessageId: $message->external_message_id,
                    occurredAt: CarbonImmutable::instance($now),
                );
            }

            throw $exception;
        }

        $this->applyStatusUpdate->execute(
            message: $message,
            incomingStatus: $result->normalizedStatus,
            providerMessageId: $result->providerMessageId,
            occurredAt: $result->occurredAt ?? CarbonImmutable::instance($now),
        );

        $message->forceFill([
            'provider' => $provider,
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();

        $attempt->forceFill([
            'status' => 'succeeded',
            'external_reference' => $result->providerMessageId,
            'provider_message_id' => $result->providerMessageId,
            'provider_status' => $result->providerStatus,
            'provider_request_id' => $result->requestId,
            'http_status' => $result->httpStatus,
            'latency_ms' => $result->latencyMs,
            'retryable' => false,
            'normalized_status' => $result->normalizedStatus->value,
            'completed_at' => $result->occurredAt ?? $now,
            'next_retry_at' => null,
            'failure_reason' => null,
            'failed_at' => null,
            'response_payload_json' => $result->responsePayload,
            'sanitized_payload_json' => $result->sanitizedRequestPayload,
        ])->save();

        return [
            'provider' => $provider,
            'message_id' => $message->id,
            'integration_attempt_id' => $attempt->id,
            'external_message_id' => $result->providerMessageId,
            'status' => $result->normalizedStatus->value,
            'sent_at' => ($result->occurredAt ?? CarbonImmutable::instance($now))->toIso8601String(),
        ];
    }

    private function makeOutboundMessageData(Message $message, int $attemptNumber): OutboundWhatsappMessageData
    {
        $recipientPhone = $message->client?->phone_e164;

        if (! is_string($recipientPhone) || $recipientPhone === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'Nao e possivel enviar WhatsApp sem phone_e164 valido para o cliente.',
                retryable: false,
            ));
        }

        return new OutboundWhatsappMessageData(
            messageId: $message->id,
            type: $message->type,
            recipientPhoneE164: $recipientPhone,
            threadKey: $message->thread_key,
            bodyText: $message->body_text,
            templateName: data_get($message->payload_json, 'template_name'),
            templateLanguage: data_get($message->payload_json, 'template_language'),
            mediaUrl: data_get($message->payload_json, 'media_url'),
            mediaMimeType: data_get($message->payload_json, 'media_mime_type'),
            mediaFilename: data_get($message->payload_json, 'media_filename'),
            caption: data_get($message->payload_json, 'caption'),
            replyToMessageId: data_get($message->payload_json, 'reply_to_message_id'),
            payload: array_merge($message->payload_json ?? [], [
                'attempt_number' => $attemptNumber,
            ]),
        );
    }

    private function capabilityForMessageType(string $type): string
    {
        return $this->capabilityGuard->capabilityForMessageType($type);
    }
}
