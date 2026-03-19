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
use App\Infrastructure\Integration\Whatsapp\WhatsappDispatchCapabilityGuard;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use App\Application\Actions\Observability\RecordWhatsappPipelineEventAction;
use Carbon\CarbonImmutable;
use Throwable;

class DispatchWhatsappMessageAction
{
    public function __construct(
        private readonly WhatsappDispatchCapabilityGuard $capabilityGuard,
        private readonly WhatsappPayloadSanitizer $sanitizer,
        private readonly ApplyWhatsappStatusUpdateAction $applyStatusUpdate,
        private readonly DetermineWhatsappFallbackDecisionAction $determineFallbackDecision,
        private readonly DetermineWhatsappProviderDispatchDecisionAction $determineDispatchDecision,
        private readonly RecordWhatsappPipelineEventAction $recordPipelineEvent,
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
            (
                $message->external_message_id !== null
                && in_array($message->status, [
                    WhatsappMessageStatus::Dispatched->value,
                    WhatsappMessageStatus::Sent->value,
                    WhatsappMessageStatus::Delivered->value,
                    WhatsappMessageStatus::Read->value,
                ], true)
            )
            || $message->status === WhatsappMessageStatus::DuplicatePrevented->value
        ) {
            return [
                'provider' => $message->provider,
                'message_id' => $message->id,
                'external_message_id' => $message->external_message_id,
                'decision' => 'already_dispatched',
                'provider_decision_source' => data_get($message->payload_json, 'provider_decision_source'),
                'decision_reason' => data_get($message->payload_json, 'decision_reason'),
                'deduplication_key' => $message->deduplication_key,
            ];
        }

        $capability = $this->capabilityForMessageType($message->type);
        $dispatchDecision = $this->determineDispatchDecision->execute(
            outboxEvent: $outboxEvent,
            message: $message,
            capability: $capability,
        );
        $outboxEvent = $this->persistDispatchContext($outboxEvent, $dispatchDecision, $message);
        /** @var ResolvedWhatsappProvider $resolvedProvider */
        $resolvedProvider = $dispatchDecision->resolvedProvider;
        $dispatchVariant = $dispatchDecision->dispatchVariant;
        $dispatchFallbackContext = $dispatchDecision->fallbackContext;
        $provider = $resolvedProvider->configuration->provider;
        $providerSlot = $resolvedProvider->configuration->slot;
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

        $outboundMessage = $this->makeOutboundMessageData($message, $outboxEvent->attempt_count);
        $requestPayload = [
            'message_id' => $message->id,
            'thread_key' => $message->thread_key,
            'type' => $message->type,
            'provider' => $provider,
            'provider_slot' => $providerSlot,
            'dispatch_variant' => $dispatchVariant,
            'provider_decision_source' => $dispatchDecision->providerDecisionSource,
            'decision_reason' => $dispatchDecision->decisionReason,
            'deduplication_key' => $message->deduplication_key,
            'payload' => $outboundMessage->payload,
            'to' => $outboundMessage->recipientPhoneE164,
            'body_text' => $outboundMessage->bodyText,
            'template_name' => $outboundMessage->templateName,
            'media_url' => $outboundMessage->mediaUrl,
            'fallback' => $dispatchFallbackContext,
        ];

        $attempt->forceFill([
            'provider' => $provider,
            'request_payload_json' => $requestPayload,
            'sanitized_payload_json' => $this->sanitizer->sanitize($requestPayload),
        ])->save();

        if (($successfulDuplicate = $this->successfulDuplicateMessage($message)) !== null) {
            $duplicatePayload = [
                'duplicate_prevented' => true,
                'duplicate_of_message_id' => $successfulDuplicate->id,
                'duplicate_of_external_message_id' => $successfulDuplicate->external_message_id,
                'duplicate_of_provider' => $successfulDuplicate->provider,
                'deduplication_key' => $message->deduplication_key,
                'provider_decision_source' => $dispatchDecision->providerDecisionSource,
                'decision_reason' => $dispatchDecision->decisionReason,
            ];

            $attempt->forceFill([
                'status' => WhatsappMessageStatus::DuplicatePrevented->value,
                'retryable' => false,
                'normalized_status' => WhatsappMessageStatus::DuplicatePrevented->value,
                'completed_at' => $now,
                'next_retry_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'response_payload_json' => $duplicatePayload,
                'sanitized_payload_json' => $this->sanitizer->sanitize($duplicatePayload),
            ])->save();

            $payloadJson = $this->messageOperationalPayload(
                $message,
                providerSlot: $providerSlot,
                dispatchVariant: $dispatchVariant,
                providerDecisionSource: $dispatchDecision->providerDecisionSource,
                decisionReason: $dispatchDecision->decisionReason,
                duplicateMetadata: array_merge($duplicatePayload, [
                    'duplicate_prevented_at' => CarbonImmutable::instance($now)->toIso8601String(),
                ]),
                fallbackContext: $dispatchFallbackContext,
            );

            $message->forceFill([
                'provider' => $provider,
                'status' => WhatsappMessageStatus::DuplicatePrevented->value,
                'payload_json' => $payloadJson,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            $this->recordPipelineEvent->execute(
                outboxEvent: $outboxEvent,
                eventName: 'whatsapp.message.duplicate_prevented',
                idempotencyKey: sprintf('whatsapp-duplicate-prevented:%s:%d', $outboxEvent->id, $outboxEvent->attempt_count),
                payload: array_merge($duplicatePayload, [
                    'message_id' => $message->id,
                    'outbox_event_id' => $outboxEvent->id,
                    'integration_attempt_id' => $attempt->id,
                ]),
                context: [
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'provider' => $provider,
                    'provider_slot' => $providerSlot,
                ],
                result: [
                    'recorded_by' => 'deduplication_guard',
                ],
                occurredAt: $now,
            );

            return [
                'provider' => $provider,
                'provider_slot' => $providerSlot,
                'message_id' => $message->id,
                'integration_attempt_id' => $attempt->id,
                'external_message_id' => null,
                'status' => WhatsappMessageStatus::DuplicatePrevented->value,
                'dispatch_variant' => $dispatchVariant,
                'provider_decision_source' => $dispatchDecision->providerDecisionSource,
                'decision_reason' => $dispatchDecision->decisionReason,
                'deduplication_key' => $message->deduplication_key,
                'duplicate_prevented' => true,
                'duplicate_risk' => false,
                'fallback' => $dispatchFallbackContext,
            ];
        }

        try {
            $this->capabilityGuard->assert($provider, $resolvedProvider->configuration, $capability);
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

            $duplicateRiskPayload = $this->recordDuplicateRiskIfNeeded(
                outboxEvent: $outboxEvent,
                message: $message,
                integrationAttempt: $attempt,
                provider: $provider,
                providerSlot: $providerSlot,
                dispatchDecision: $dispatchDecision,
                exception: $exception,
                occurredAt: CarbonImmutable::instance($now),
            );

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
                    $dispatchDecision->providerDecisionSource,
                    $dispatchDecision->decisionReason,
                    $dispatchFallbackContext,
                    $fallbackDecision->toArray(),
                    $duplicateRiskPayload,
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
                    'provider' => $provider,
                    'payload_json' => $this->messageOperationalPayload(
                        $message,
                        providerSlot: $providerSlot,
                        dispatchVariant: $dispatchVariant,
                        providerDecisionSource: $dispatchDecision->providerDecisionSource,
                        decisionReason: $dispatchDecision->decisionReason,
                        duplicateMetadata: $duplicateRiskPayload,
                        fallbackContext: array_merge($fallbackDecision->toArray(), [
                            'scheduled_at' => $nextRetryAt->toIso8601String(),
                        ]),
                    ),
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
                $dispatchDecision->providerDecisionSource,
                $dispatchDecision->decisionReason,
                $dispatchFallbackContext,
                null,
                $duplicateRiskPayload,
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
                'provider' => $provider,
                'payload_json' => $this->messageOperationalPayload(
                    $message,
                    providerSlot: $providerSlot,
                    dispatchVariant: $dispatchVariant,
                    providerDecisionSource: $dispatchDecision->providerDecisionSource,
                    decisionReason: $dispatchDecision->decisionReason,
                    duplicateMetadata: $duplicateRiskPayload,
                    fallbackContext: $dispatchFallbackContext,
                ),
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

        $message->forceFill([
            'provider' => $provider,
            'payload_json' => $this->messageOperationalPayload(
                $message,
                providerSlot: $providerSlot,
                dispatchVariant: $dispatchVariant,
                providerDecisionSource: $dispatchDecision->providerDecisionSource,
                decisionReason: $dispatchDecision->decisionReason,
                duplicateMetadata: [
                    'duplicate_prevented' => false,
                    'duplicate_risk_detected' => false,
                ],
                fallbackContext: $dispatchFallbackContext !== null
                    ? array_merge($dispatchFallbackContext, [
                        'used' => true,
                        'to_provider' => $provider,
                        'to_slot' => $providerSlot,
                        'executed_at' => $occurredAt->toIso8601String(),
                    ])
                    : null,
            ),
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();

        $attemptResponsePayload = [
            'provider_response' => $result->responsePayload,
            'dispatch_variant' => $dispatchVariant,
            'provider_slot' => $providerSlot,
            'provider_decision_source' => $dispatchDecision->providerDecisionSource,
            'decision_reason' => $dispatchDecision->decisionReason,
            'deduplication_key' => $message->deduplication_key,
            'duplicate_prevented' => false,
            'duplicate_risk' => false,
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
            'provider_decision_source' => $dispatchDecision->providerDecisionSource,
            'decision_reason' => $dispatchDecision->decisionReason,
            'deduplication_key' => $message->deduplication_key,
            'duplicate_prevented' => false,
            'duplicate_risk' => false,
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

    private function successfulDuplicateMessage(Message $message): ?Message
    {
        if (! is_string($message->deduplication_key) || $message->deduplication_key === '') {
            return null;
        }

        return Message::query()
            ->where('deduplication_key', $message->deduplication_key)
            ->whereKeyNot($message->id)
            ->whereHas('integrationAttempts', function ($query): void {
                $query
                    ->where('operation', 'send_message')
                    ->where('status', 'succeeded');
            })
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $plannedFallback
     * @param  array<string, mixed>|null  $duplicateRisk
     * @return array<string, mixed>
     */
    private function failurePayload(
        WhatsappProviderException $exception,
        string $dispatchVariant,
        string $providerSlot,
        string $providerDecisionSource,
        string $decisionReason,
        ?array $activeFallback = null,
        ?array $plannedFallback = null,
        ?array $duplicateRisk = null,
    ): array
    {
        return array_filter([
            'error' => $exception->error->details,
            'dispatch_variant' => $dispatchVariant,
            'provider_slot' => $providerSlot,
            'provider_decision_source' => $providerDecisionSource,
            'decision_reason' => $decisionReason,
            'active_fallback' => $activeFallback,
            'planned_fallback' => $plannedFallback,
            'duplicate_risk' => $duplicateRisk,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $duplicateMetadata
     * @param  array<string, mixed>|null  $fallbackContext
     * @return array<string, mixed>
     */
    private function messageOperationalPayload(
        Message $message,
        string $providerSlot,
        string $dispatchVariant,
        string $providerDecisionSource,
        string $decisionReason,
        ?array $duplicateMetadata = null,
        ?array $fallbackContext = null,
    ): array {
        $payloadJson = is_array($message->payload_json) ? $message->payload_json : [];
        $existingDeduplication = is_array(data_get($payloadJson, 'deduplication'))
            ? (array) data_get($payloadJson, 'deduplication')
            : [];

        $payloadJson['provider_slot'] = $providerSlot;
        $payloadJson['dispatch_variant'] = $dispatchVariant;
        $payloadJson['provider_decision_source'] = $providerDecisionSource;
        $payloadJson['decision_reason'] = $decisionReason;

        $payloadJson['deduplication'] = array_merge($existingDeduplication, array_filter([
            'key' => $message->deduplication_key,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), $duplicateMetadata ?? []);

        if ($fallbackContext !== null) {
            $payloadJson['fallback'] = $fallbackContext;
        }

        return $payloadJson;
    }

    private function persistDispatchContext(
        OutboxEvent $outboxEvent,
        \App\Application\DTOs\WhatsappProviderDispatchDecision $dispatchDecision,
        Message $message,
    ): OutboxEvent {
        $context = is_array($outboxEvent->context_json) ? $outboxEvent->context_json : [];
        $context['whatsapp_dispatch'] = [
            'provider' => $dispatchDecision->resolvedProvider->configuration->provider,
            'provider_slot' => $dispatchDecision->resolvedProvider->configuration->slot,
            'dispatch_variant' => $dispatchDecision->dispatchVariant,
            'provider_decision_source' => $dispatchDecision->providerDecisionSource,
            'decision_reason' => $dispatchDecision->decisionReason,
            'deduplication_key' => $message->deduplication_key,
        ];

        $outboxEvent->forceFill([
            'context_json' => $context,
        ])->save();

        return $outboxEvent->fresh(['eventLog', 'message']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recordDuplicateRiskIfNeeded(
        OutboxEvent $outboxEvent,
        Message $message,
        IntegrationAttempt $integrationAttempt,
        string $provider,
        string $providerSlot,
        \App\Application\DTOs\WhatsappProviderDispatchDecision $dispatchDecision,
        WhatsappProviderException $exception,
        CarbonImmutable $occurredAt,
    ): ?array {
        if (! in_array($exception->error->code, [
            WhatsappProviderErrorCode::TimeoutError,
            WhatsappProviderErrorCode::TransientNetworkError,
        ], true)) {
            return null;
        }

        $payload = [
            'duplicate_risk_detected' => true,
            'risk_error_code' => $exception->error->code->value,
            'deduplication_key' => $message->deduplication_key,
            'provider_decision_source' => $dispatchDecision->providerDecisionSource,
            'decision_reason' => $dispatchDecision->decisionReason,
            'detected_at' => $occurredAt->toIso8601String(),
        ];

        $message->forceFill([
            'payload_json' => $this->messageOperationalPayload(
                $message,
                providerSlot: $providerSlot,
                dispatchVariant: $dispatchDecision->dispatchVariant,
                providerDecisionSource: $dispatchDecision->providerDecisionSource,
                decisionReason: $dispatchDecision->decisionReason,
                duplicateMetadata: $payload,
                fallbackContext: $dispatchDecision->fallbackContext,
            ),
        ])->save();

        $this->recordPipelineEvent->execute(
            outboxEvent: $outboxEvent,
            eventName: 'whatsapp.message.duplicate_risk_detected',
            idempotencyKey: sprintf('whatsapp-duplicate-risk:%s:%d', $outboxEvent->id, $outboxEvent->attempt_count),
            payload: array_merge($payload, [
                'message_id' => $message->id,
                'outbox_event_id' => $outboxEvent->id,
                'integration_attempt_id' => $integrationAttempt->id,
                'provider' => $provider,
                'provider_slot' => $providerSlot,
            ]),
            context: [
                'channel' => 'whatsapp',
                'direction' => 'outbound',
                'provider' => $provider,
                'provider_slot' => $providerSlot,
            ],
            result: [
                'recorded_by' => 'deduplication_guard',
            ],
            occurredAt: $occurredAt,
        );

        return $payload;
    }
}
