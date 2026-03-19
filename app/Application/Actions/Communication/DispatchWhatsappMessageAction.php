<?php

namespace App\Application\Actions\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Data\ResolvedWhatsappProvider;
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderFallbackException;
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
        private readonly DetermineWhatsappFallbackDecisionAction $determineFallbackDecision,
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

        $route = $this->resolveDispatchRoute($outboxEvent, $message);
        /** @var ResolvedWhatsappProvider $resolvedProvider */
        $resolvedProvider = $route['resolved_provider'];
        $dispatchVariant = $route['dispatch_variant'];
        $dispatchFallbackContext = $route['fallback_context'];
        $provider = $resolvedProvider->configuration->provider;
        $providerSlot = $resolvedProvider->configuration->slot;
        $capability = $this->capabilityForMessageType($message->type);
        $attempt = IntegrationAttempt::query()->firstOrCreate([
            'idempotency_key' => sprintf(
                'whatsapp-dispatch:%s:%d:%s:%s',
                $outboxEvent->id,
                $outboxEvent->attempt_count,
                $providerSlot,
                $dispatchVariant,
            ),
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
                'provider_slot' => $providerSlot,
                'dispatch_variant' => $dispatchVariant,
                'payload' => $outboundMessage->payload,
                'to' => $outboundMessage->recipientPhoneE164,
                'body_text' => $outboundMessage->bodyText,
                'template_name' => $outboundMessage->templateName,
                'media_url' => $outboundMessage->mediaUrl,
                'fallback' => $dispatchFallbackContext,
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

            $fallbackDecision = $this->determineFallbackDecision->execute(
                outboxEvent: $outboxEvent,
                resolvedProvider: $resolvedProvider,
                capability: $capability,
                error: $exception->error,
            );

            if ($fallbackDecision !== null) {
                $nextRetryAt = $now->copy()->addSeconds($fallbackDecision->backoffSeconds);
                $failurePayload = $this->failurePayload(
                    $exception,
                    $dispatchVariant,
                    $providerSlot,
                    $dispatchFallbackContext,
                    $fallbackDecision->toArray(),
                );

                $attempt->forceFill([
                    'status' => 'fallback_scheduled',
                    'provider_error_code' => $exception->error->providerCode,
                    'http_status' => $exception->error->httpStatus,
                    'provider_request_id' => $exception->error->requestId,
                    'retryable' => true,
                    'normalized_error_code' => $exception->error->code->value,
                    'next_retry_at' => $nextRetryAt,
                    'failed_at' => null,
                    'failure_reason' => $exception->error->message,
                    'response_payload_json' => $failurePayload,
                    'sanitized_payload_json' => $this->sanitizer->sanitize($failurePayload),
                ])->save();

                $message->forceFill([
                    'failure_reason' => $exception->error->message,
                    'failed_at' => null,
                ])->save();

                throw new WhatsappProviderFallbackException(
                    fallbackDecision: $fallbackDecision,
                    error: $exception->error,
                    previous: $throwable,
                );
            }

            $willRetry = $exception->isRetryable() && $outboxEvent->attempt_count < $outboxEvent->max_attempts;
            $nextRetryAt = $willRetry ? $now->copy()->addSeconds($outboxEvent->retry_backoff_seconds) : null;
            $failurePayload = $this->failurePayload(
                $exception,
                $dispatchVariant,
                $providerSlot,
                $dispatchFallbackContext,
            );

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
                'response_payload_json' => $failurePayload,
                'sanitized_payload_json' => $this->sanitizer->sanitize($failurePayload),
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

        $occurredAt = $result->occurredAt ?? CarbonImmutable::instance($now);

        $message = $this->applyStatusUpdate->execute(
            message: $message,
            incomingStatus: $result->normalizedStatus,
            providerMessageId: $result->providerMessageId,
            occurredAt: $occurredAt,
        );

        $payloadJson = $message->payload_json ?? [];
        $payloadJson['provider_slot'] = $providerSlot;
        $payloadJson['dispatch_variant'] = $dispatchVariant;

        if ($dispatchFallbackContext !== null) {
            $payloadJson['fallback'] = [
                'used' => true,
                'from_provider' => data_get($dispatchFallbackContext, 'from_provider'),
                'from_slot' => data_get($dispatchFallbackContext, 'from_slot'),
                'to_provider' => $provider,
                'to_slot' => $providerSlot,
                'trigger_error_code' => data_get($dispatchFallbackContext, 'trigger_error_code'),
                'scheduled_at' => data_get($dispatchFallbackContext, 'scheduled_at'),
                'executed_at' => $occurredAt->toIso8601String(),
            ];
        }

        $message->forceFill([
            'provider' => $provider,
            'payload_json' => $payloadJson,
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();

        $attemptResponsePayload = [
            'provider_response' => $result->responsePayload,
            'dispatch_variant' => $dispatchVariant,
            'provider_slot' => $providerSlot,
            'fallback' => $dispatchFallbackContext,
        ];

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
            'response_payload_json' => $attemptResponsePayload,
            'sanitized_payload_json' => $this->sanitizer->sanitize($attemptResponsePayload),
        ])->save();

        return [
            'provider' => $provider,
            'provider_slot' => $providerSlot,
            'message_id' => $message->id,
            'integration_attempt_id' => $attempt->id,
            'external_message_id' => $result->providerMessageId,
            'status' => $result->normalizedStatus->value,
            'sent_at' => $occurredAt->toIso8601String(),
            'dispatch_variant' => $dispatchVariant,
            'fallback' => $dispatchFallbackContext,
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

    /**
     * @return array{
     *     resolved_provider: ResolvedWhatsappProvider,
     *     dispatch_variant: string,
     *     fallback_context: array<string, mixed>|null
     * }
     */
    private function resolveDispatchRoute(OutboxEvent $outboxEvent, Message $message): array
    {
        $fallbackContext = $this->fallbackContext($outboxEvent);

        if ($fallbackContext !== null) {
            return [
                'resolved_provider' => $this->providerResolver->resolveBySlot((string) data_get($fallbackContext, 'to_slot', 'secondary')),
                'dispatch_variant' => 'fallback',
                'fallback_context' => $fallbackContext,
            ];
        }

        return [
            'resolved_provider' => $this->providerResolver->resolveForOutbound($message->provider),
            'dispatch_variant' => 'primary',
            'fallback_context' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fallbackContext(OutboxEvent $outboxEvent): ?array
    {
        $fallback = data_get($outboxEvent->context_json ?? [], 'whatsapp_fallback');

        if (! is_array($fallback) || ! (bool) ($fallback['active'] ?? false)) {
            return null;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>|null  $activeFallback
     * @param  array<string, mixed>|null  $plannedFallback
     * @return array<string, mixed>
     */
    private function failurePayload(
        WhatsappProviderException $exception,
        string $dispatchVariant,
        string $providerSlot,
        ?array $activeFallback = null,
        ?array $plannedFallback = null,
    ): array {
        return array_filter([
            'error' => $exception->error->details,
            'dispatch_variant' => $dispatchVariant,
            'provider_slot' => $providerSlot,
            'active_fallback' => $activeFallback,
            'planned_fallback' => $plannedFallback,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
