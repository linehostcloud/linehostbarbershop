<?php

namespace App\Application\Actions\Observability;

use App\Application\Actions\Communication\DispatchWhatsappMessageAction;
use App\Application\Actions\Communication\ProcessWhatsappWebhookAction;
use App\Domain\Communication\Exceptions\WhatsappProviderFallbackException;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOutboxEventAction
{
    public function __construct(
        private readonly DispatchWhatsappMessageAction $dispatchWhatsappMessage,
        private readonly ProcessWhatsappWebhookAction $processWhatsappWebhook,
    ) {}

    public function execute(OutboxEvent $outboxEvent): OutboxEvent
    {
        $claimedEvent = $this->claim($outboxEvent);

        if ($claimedEvent->status !== 'processing') {
            return $claimedEvent;
        }

        try {
            $result = $this->handle($claimedEvent);

            return $this->markProcessed($claimedEvent, $result);
        } catch (Throwable $throwable) {
            return $this->markFailure($claimedEvent, $throwable);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(OutboxEvent $outboxEvent): array
    {
        return match ($outboxEvent->event_name) {
            'appointment.created',
            'order.closed' => [
                'processor' => 'automation_audit',
                'decision' => 'event_recorded_for_future_rules',
            ],
            'whatsapp.message.dispatch.requested' => $this->dispatchWhatsappMessage->execute($outboxEvent),
            'whatsapp.webhook.process.requested' => $this->processWhatsappWebhook->execute($outboxEvent),
            default => [
                'processor' => 'outbox',
                'decision' => 'ignored',
                'reason' => 'Nenhum handler registrado para o evento.',
            ],
        };
    }

    private function claim(OutboxEvent $outboxEvent): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent) {
            $updated = OutboxEvent::query()
                ->whereKey($outboxEvent->id)
                ->whereIn('status', ['pending', 'retry_scheduled'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('available_at')
                        ->orWhere('available_at', '<=', now());
                })
                ->update([
                    'status' => 'processing',
                    'attempt_count' => DB::raw('attempt_count + 1'),
                    'reserved_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()->with('eventLog', 'message')->findOrFail($outboxEvent->id);

            if ($updated === 0) {
                return $freshEvent->fresh(['eventLog', 'message']);
            }

            $freshEvent->eventLog?->forceFill([
                'status' => 'processing',
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            return $freshEvent->fresh(['eventLog', 'message']);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markProcessed(OutboxEvent $outboxEvent, array $result): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent, $result) {
            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()
                ->with(['eventLog', 'message', 'integrationAttempts'])
                ->findOrFail($outboxEvent->id);

            $freshEvent->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            $freshEvent->eventLog?->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'result_json' => $result,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            if (($result['dispatch_variant'] ?? null) === 'fallback' && is_array($result['fallback'] ?? null)) {
                $this->recordFallbackEvent(
                    freshEvent: $freshEvent,
                    eventName: 'whatsapp.message.fallback.executed',
                    idempotencyKey: sprintf('whatsapp-fallback-executed:%s:%d', $freshEvent->id, $freshEvent->attempt_count),
                    payload: [
                        'message_id' => $freshEvent->message_id,
                        'outbox_event_id' => $freshEvent->id,
                        'integration_attempt_id' => $result['integration_attempt_id'] ?? null,
                        'provider' => $result['provider'] ?? null,
                        'provider_slot' => $result['provider_slot'] ?? null,
                        'fallback' => $result['fallback'],
                    ],
                    occurredAt: $freshEvent->processed_at ?? now(),
                );
            }

            return $freshEvent->fresh(['eventLog', 'message', 'integrationAttempts']);
        });
    }

    private function markFailure(OutboxEvent $outboxEvent, Throwable $throwable): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent, $throwable) {
            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()
                ->with(['eventLog', 'message', 'integrationAttempts'])
                ->findOrFail($outboxEvent->id);

            $fallbackDecision = $throwable instanceof WhatsappProviderFallbackException
                ? $throwable->fallbackDecision
                : null;
            $willRetry = $fallbackDecision !== null ? true : $this->shouldRetry($freshEvent, $throwable);
            $nextAttemptAt = $willRetry
                ? now()->addSeconds($fallbackDecision?->backoffSeconds ?? $freshEvent->retry_backoff_seconds)
                : null;
            $context = is_array($freshEvent->context_json) ? $freshEvent->context_json : [];

            if ($fallbackDecision !== null && $nextAttemptAt !== null) {
                $context['whatsapp_fallback'] = array_merge($fallbackDecision->toArray(), [
                    'active' => true,
                    'scheduled_at' => now()->toIso8601String(),
                    'execute_after' => $nextAttemptAt->toIso8601String(),
                ]);
            }

            $freshEvent->forceFill([
                'status' => $willRetry ? 'retry_scheduled' : 'failed',
                'available_at' => $nextAttemptAt ?: $freshEvent->available_at,
                'context_json' => $context !== [] ? $context : $freshEvent->context_json,
                'failed_at' => $willRetry ? null : now(),
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            $freshEvent->eventLog?->forceFill([
                'status' => $willRetry ? 'retry_scheduled' : 'failed',
                'result_json' => array_filter([
                    'error' => $throwable->getMessage(),
                    'next_retry_at' => $nextAttemptAt?->toIso8601String(),
                    'fallback' => $fallbackDecision !== null
                        ? array_merge($fallbackDecision->toArray(), [
                            'scheduled_at' => data_get($context, 'whatsapp_fallback.scheduled_at'),
                            'execute_after' => data_get($context, 'whatsapp_fallback.execute_after'),
                        ])
                        : null,
                ], static fn (mixed $value): bool => $value !== null),
                'failed_at' => $willRetry ? null : now(),
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            if ($fallbackDecision !== null) {
                $this->recordFallbackEvent(
                    freshEvent: $freshEvent,
                    eventName: 'whatsapp.message.fallback.scheduled',
                    idempotencyKey: sprintf('whatsapp-fallback-scheduled:%s:%d', $freshEvent->id, $freshEvent->attempt_count),
                    payload: [
                        'message_id' => $freshEvent->message_id,
                        'outbox_event_id' => $freshEvent->id,
                        'retry_at' => $nextAttemptAt?->toIso8601String(),
                        'fallback' => data_get($context, 'whatsapp_fallback'),
                    ],
                    occurredAt: now(),
                );
            }

            return $freshEvent->fresh(['eventLog', 'message', 'integrationAttempts']);
        });
    }

    private function shouldRetry(OutboxEvent $outboxEvent, Throwable $throwable): bool
    {
        if ($outboxEvent->attempt_count >= $outboxEvent->max_attempts) {
            return false;
        }

        if ($throwable instanceof WhatsappProviderException) {
            return $throwable->isRetryable();
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordFallbackEvent(
        OutboxEvent $freshEvent,
        string $eventName,
        string $idempotencyKey,
        array $payload,
        \DateTimeInterface $occurredAt,
    ): void {
        EventLog::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'message_id' => $freshEvent->message_id,
                'aggregate_type' => 'message',
                'aggregate_id' => $freshEvent->message_id,
                'event_name' => $eventName,
                'trigger_source' => 'system',
                'status' => 'processed',
                'correlation_id' => $freshEvent->eventLog?->correlation_id,
                'causation_id' => $freshEvent->event_log_id,
                'payload_json' => $payload,
                'context_json' => [
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'provider' => data_get($payload, 'provider') ?? data_get($payload, 'fallback.to_provider'),
                    'provider_slot' => data_get($payload, 'provider_slot') ?? data_get($payload, 'fallback.to_slot'),
                ],
                'result_json' => [
                    'recorded_by' => 'fallback_control',
                ],
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ],
        );
    }
}
