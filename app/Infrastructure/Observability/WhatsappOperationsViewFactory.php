<?php

namespace App\Infrastructure\Observability;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;

class WhatsappOperationsViewFactory
{
    public function __construct(
        private readonly WhatsappPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $lastHealthcheck
     * @param  array<int, array{code:string,total:int}>  $topErrorCodes
     * @return array<string, mixed>
     */
    public function providerHealth(
        WhatsappProviderConfig $configuration,
        int $sendAttemptsTotal,
        int $successAttempts,
        int $failureAttempts,
        int $retryScheduledTotal,
        int $fallbackScheduledTotal,
        int $fallbackExecutedTotal,
        array $signalTotals,
        array $topErrorCodes,
        ?array $lastHealthcheck,
        ?string $lastActivityAt,
    ): array {
        return [
            'slot' => $configuration->slot,
            'provider' => $configuration->provider,
            'enabled' => (bool) $configuration->enabled,
            'enabled_capabilities' => $configuration->enabledCapabilities(),
            'last_healthcheck' => $lastHealthcheck,
            'send_attempts_total' => $sendAttemptsTotal,
            'success_attempts' => $successAttempts,
            'failure_attempts' => $failureAttempts,
            'retry_scheduled_total' => $retryScheduledTotal,
            'fallback_scheduled_total' => $fallbackScheduledTotal,
            'fallback_executed_total' => $fallbackExecutedTotal,
            'success_rate' => $sendAttemptsTotal > 0
                ? round(($successAttempts / $sendAttemptsTotal) * 100, 2)
                : 0.0,
            'failure_rate' => $sendAttemptsTotal > 0
                ? round(($failureAttempts / $sendAttemptsTotal) * 100, 2)
                : 0.0,
            'signal_totals' => $signalTotals,
            'top_error_codes' => $topErrorCodes,
            'operational_state' => $this->providerOperationalState(
                configuration: $configuration,
                sendAttemptsTotal: $sendAttemptsTotal,
                failureAttempts: $failureAttempts,
                retryScheduledTotal: $retryScheduledTotal,
                fallbackScheduledTotal: $fallbackScheduledTotal,
                fallbackExecutedTotal: $fallbackExecutedTotal,
                signalTotals: $signalTotals,
                lastHealthcheck: $lastHealthcheck,
            ),
            'last_activity_at' => $lastActivityAt,
            'last_validated_at' => $configuration->last_validated_at?->toIso8601String(),
            'updated_at' => $configuration->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function boundarySummaryItem(BoundaryRejectionAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'direction' => $audit->direction,
            'endpoint' => $audit->endpoint,
            'method' => $audit->method,
            'provider' => $audit->provider,
            'slot' => $audit->slot,
            'code' => $audit->code,
            'message' => $audit->message,
            'http_status' => $audit->http_status,
            'request_id' => $audit->request_id,
            'correlation_id' => $audit->correlation_id,
            'occurred_at' => $audit->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueOutboxItem(OutboxEvent $outboxEvent, string $attentionType): array
    {
        $message = $outboxEvent->message;
        $slot = $this->slotFromMessage($message) ?? $this->slotFromOutbox($outboxEvent);
        $fallback = $this->sanitizeFallback(data_get($outboxEvent->context_json, 'whatsapp_fallback'));

        return [
            'source' => 'outbox_event',
            'attention_type' => $attentionType,
            'provider' => $message?->provider,
            'slot' => $slot,
            'status' => $outboxEvent->status,
            'error_code' => null,
            'severity' => $attentionType === 'outbox_manual_review_required' ? 'high' : 'medium',
            'occurred_at' => match ($attentionType) {
                'outbox_reclaimed_recently' => $outboxEvent->last_reclaimed_at?->toIso8601String(),
                default => $outboxEvent->failed_at?->toIso8601String() ?: $outboxEvent->updated_at?->toIso8601String(),
            },
            'message_id' => $outboxEvent->message_id,
            'outbox_event_id' => $outboxEvent->id,
            'integration_attempt_id' => null,
            'decision_source' => $fallback !== null ? 'fallback' : ($slot ?? 'unknown'),
            'fallback' => $fallback,
            'event_name' => $outboxEvent->event_name,
            'summary' => $outboxEvent->failure_reason ?: $outboxEvent->last_reclaim_reason ?: 'Item de outbox exige atencao operacional.',
            'details' => [
                'attempt_count' => $outboxEvent->attempt_count,
                'max_attempts' => $outboxEvent->max_attempts,
                'reclaim_count' => $outboxEvent->reclaim_count,
                'last_reclaim_reason' => $outboxEvent->last_reclaim_reason,
                'last_reclaimed_at' => $outboxEvent->last_reclaimed_at?->toIso8601String(),
                'failed_at' => $outboxEvent->failed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueMessageItem(Message $message): array
    {
        $fallback = $this->sanitizeFallback(data_get($message->payload_json, 'fallback'));

        return [
            'source' => 'message',
            'attention_type' => 'message_terminal_failure',
            'provider' => $message->provider,
            'slot' => $this->slotFromMessage($message),
            'status' => $message->status,
            'error_code' => null,
            'severity' => 'high',
            'occurred_at' => $message->failed_at?->toIso8601String() ?: $message->updated_at?->toIso8601String(),
            'message_id' => $message->id,
            'outbox_event_id' => null,
            'integration_attempt_id' => null,
            'decision_source' => $fallback !== null ? 'fallback' : ($this->slotFromMessage($message) ?? 'unknown'),
            'fallback' => $fallback,
            'event_name' => 'message.failed',
            'summary' => $message->failure_reason ?: 'Mensagem com falha terminal.',
            'details' => [
                'type' => $message->type,
                'failed_at' => $message->failed_at?->toIso8601String(),
                'external_message_id' => $message->external_message_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueIntegrationAttemptItem(IntegrationAttempt $attempt): array
    {
        $message = $attempt->message;
        $plannedFallback = is_array(data_get($attempt->response_payload_json, 'planned_fallback'))
            ? $this->sanitizer->sanitize((array) data_get($attempt->response_payload_json, 'planned_fallback'))
            : null;
        $activeFallback = is_array(data_get($attempt->response_payload_json, 'active_fallback'))
            ? $this->sanitizer->sanitize((array) data_get($attempt->response_payload_json, 'active_fallback'))
            : null;

        return [
            'source' => 'integration_attempt',
            'attention_type' => 'integration_attempt_issue',
            'provider' => $attempt->provider,
            'slot' => $this->slotFromMessage($message),
            'status' => $attempt->status,
            'error_code' => $attempt->normalized_error_code,
            'severity' => in_array($attempt->normalized_error_code, ['unsupported_feature', 'provider_unavailable'], true)
                ? 'high'
                : 'medium',
            'occurred_at' => $attempt->failed_at?->toIso8601String()
                ?: $attempt->last_attempt_at?->toIso8601String()
                ?: $attempt->created_at?->toIso8601String(),
            'message_id' => $attempt->message_id,
            'outbox_event_id' => $attempt->outbox_event_id,
            'integration_attempt_id' => $attempt->id,
            'decision_source' => $activeFallback !== null || $plannedFallback !== null
                ? 'fallback'
                : ($this->slotFromMessage($message) ?? 'unknown'),
            'fallback' => $activeFallback ?? $plannedFallback,
            'event_name' => 'integration_attempt.issue',
            'summary' => $attempt->failure_reason ?: 'Tentativa de integracao exige atencao operacional.',
            'details' => [
                'operation' => $attempt->operation,
                'direction' => $attempt->direction,
                'http_status' => $attempt->http_status,
                'attempt_count' => $attempt->attempt_count,
                'retryable' => $attempt->retryable,
                'provider_error_code' => $attempt->provider_error_code,
                'provider_status' => $attempt->provider_status,
                'planned_fallback' => $plannedFallback,
                'active_fallback' => $activeFallback,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedAuditItem(AuditLog $audit): array
    {
        $action = (string) $audit->action;
        $provider = $this->auditProvider($audit);
        $slot = $this->auditSlot($audit);
        $result = is_array(data_get($audit->metadata_json, 'result'))
            ? $this->sanitizer->sanitize((array) data_get($audit->metadata_json, 'result'))
            : null;

        return [
            'source' => 'admin_audit',
            'type' => match ($action) {
                'whatsapp_provider_config.activated' => 'provider_config_activated',
                'whatsapp_provider_config.deactivated' => 'provider_config_deactivated',
                default => 'provider_healthcheck',
            },
            'provider' => $provider,
            'slot' => $slot,
            'status' => $action === 'whatsapp_provider_config.healthcheck_requested'
                ? ((bool) data_get($result, 'healthy') ? 'healthy' : 'unhealthy')
                : null,
            'error_code' => is_string(data_get($result, 'error.code')) ? (string) data_get($result, 'error.code') : null,
            'direction' => null,
            'severity' => match ($action) {
                'whatsapp_provider_config.deactivated' => 'high',
                'whatsapp_provider_config.healthcheck_requested' => (bool) data_get($result, 'healthy') ? 'info' : 'medium',
                default => 'info',
            },
            'occurred_at' => $audit->created_at?->toIso8601String(),
            'reference_id' => $audit->id,
            'message' => match ($action) {
                'whatsapp_provider_config.activated' => 'Configuracao de provider ativada.',
                'whatsapp_provider_config.deactivated' => 'Configuracao de provider desativada.',
                default => 'Healthcheck administrativo executado.',
            },
            'details' => [
                'action' => $action,
                'result' => $result,
                'slot' => $slot,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedEventLogItem(EventLog $eventLog): array
    {
        $message = $eventLog->message;
        $reason = (string) (data_get($eventLog->payload_json, 'reason') ?? data_get($eventLog->result_json, 'reason') ?? '');
        $type = match ($eventLog->event_name) {
            'outbox.event.reclaim.blocked' => 'manual_review_required',
            'whatsapp.message.fallback.scheduled' => 'provider_fallback_scheduled',
            'whatsapp.message.fallback.executed' => 'provider_fallback_executed',
            default => 'outbox_reclaimed',
        };
        $provider = is_string(data_get($eventLog->context_json, 'provider')) && data_get($eventLog->context_json, 'provider') !== ''
            ? (string) data_get($eventLog->context_json, 'provider')
            : $message?->provider;
        $slot = is_string(data_get($eventLog->context_json, 'provider_slot')) && data_get($eventLog->context_json, 'provider_slot') !== ''
            ? (string) data_get($eventLog->context_json, 'provider_slot')
            : $this->slotFromMessage($message);

        return [
            'source' => 'event_log',
            'type' => $type,
            'provider' => $provider,
            'slot' => $slot,
            'status' => $eventLog->status,
            'error_code' => is_string(data_get($eventLog->payload_json, 'fallback.trigger_error_code'))
                ? (string) data_get($eventLog->payload_json, 'fallback.trigger_error_code')
                : null,
            'direction' => null,
            'severity' => match ($eventLog->event_name) {
                'outbox.event.reclaim.blocked' => 'high',
                'whatsapp.message.fallback.executed' => 'medium',
                default => 'medium',
            },
            'occurred_at' => $eventLog->occurred_at?->toIso8601String(),
            'reference_id' => $eventLog->id,
            'message' => match ($eventLog->event_name) {
                'outbox.event.reclaim.blocked' => 'Reclaim automatico bloqueado; revisao manual exigida.',
                'whatsapp.message.fallback.scheduled' => 'Fallback controlado agendado para o provider secundario.',
                'whatsapp.message.fallback.executed' => 'Fallback controlado executado no provider secundario.',
                default => 'Outbox stale recolocado para retry.',
            },
            'details' => [
                'event_name' => $eventLog->event_name,
                'message_id' => $eventLog->message_id,
                'aggregate_id' => $eventLog->aggregate_id,
                'provider' => $provider,
                'slot' => $slot,
                'reason' => $reason !== '' ? $reason : null,
                'payload' => $this->sanitizer->sanitize($eventLog->payload_json ?? []),
                'result' => $this->sanitizer->sanitize($eventLog->result_json ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedBoundaryItem(BoundaryRejectionAudit $audit): array
    {
        return [
            'source' => 'boundary_rejection_audit',
            'type' => 'boundary_rejection',
            'provider' => $audit->provider,
            'slot' => $audit->slot,
            'status' => null,
            'error_code' => $audit->code,
            'direction' => $audit->direction,
            'severity' => $audit->code === 'webhook_signature_invalid' ? 'high' : 'medium',
            'occurred_at' => $audit->occurred_at?->toIso8601String(),
            'reference_id' => $audit->id,
            'message' => $audit->message,
            'details' => [
                'endpoint' => $audit->endpoint,
                'method' => $audit->method,
                'http_status' => $audit->http_status,
                'request_id' => $audit->request_id,
                'correlation_id' => $audit->correlation_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedIntegrationAttemptItem(IntegrationAttempt $attempt): array
    {
        return [
            'source' => 'integration_attempt',
            'type' => 'terminal_failure',
            'provider' => $attempt->provider,
            'slot' => $this->slotFromMessage($attempt->message),
            'status' => $attempt->status,
            'error_code' => $attempt->normalized_error_code,
            'direction' => $attempt->direction,
            'severity' => 'high',
            'occurred_at' => $attempt->failed_at?->toIso8601String()
                ?: $attempt->last_attempt_at?->toIso8601String()
                ?: $attempt->created_at?->toIso8601String(),
            'reference_id' => $attempt->id,
            'message' => $attempt->failure_reason ?: 'Falha terminal de integracao de WhatsApp.',
            'details' => [
                'message_id' => $attempt->message_id,
                'outbox_event_id' => $attempt->outbox_event_id,
                'operation' => $attempt->operation,
                'http_status' => $attempt->http_status,
                'provider_error_code' => $attempt->provider_error_code,
                'slot' => $this->slotFromMessage($attempt->message),
            ],
        ];
    }

    public function slotFromMessage(?Message $message): ?string
    {
        $slot = data_get($message?->payload_json ?? [], 'provider_slot');

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    public function slotFromOutbox(OutboxEvent $outboxEvent): ?string
    {
        $slot = data_get($outboxEvent->eventLog?->context_json ?? [], 'provider_slot');

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    private function auditProvider(AuditLog $audit): ?string
    {
        $provider = data_get($audit->metadata_json, 'provider')
            ?? data_get($audit->after_json, 'provider')
            ?? data_get($audit->before_json, 'provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function auditSlot(AuditLog $audit): ?string
    {
        $slot = data_get($audit->metadata_json, 'slot')
            ?? data_get($audit->after_json, 'slot')
            ?? data_get($audit->before_json, 'slot');

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    /**
     * @param  array<int, array{code:string,total:int}>  $signalTotals
     * @param  array<string, mixed>|null  $lastHealthcheck
     * @return array<string, string|bool|null>
     */
    private function providerOperationalState(
        WhatsappProviderConfig $configuration,
        int $sendAttemptsTotal,
        int $failureAttempts,
        int $retryScheduledTotal,
        int $fallbackScheduledTotal,
        int $fallbackExecutedTotal,
        array $signalTotals,
        ?array $lastHealthcheck,
    ): array {
        $healthFailed = $lastHealthcheck !== null && ! (bool) ($lastHealthcheck['healthy'] ?? false);
        $failureRate = $sendAttemptsTotal > 0
            ? ($failureAttempts / $sendAttemptsTotal) * 100
            : 0.0;
        $hasUnavailableSignal = $this->signalPresent($signalTotals, 'provider_unavailable');
        $hasTimeoutSignal = $this->signalPresent($signalTotals, 'timeout_error');
        $hasRateLimitSignal = $this->signalPresent($signalTotals, 'rate_limit');
        $hasTransientSignal = $this->signalPresent($signalTotals, 'transient_network_error');

        if (! $configuration->enabled) {
            return [
                'label' => 'disabled',
                'tone' => 'stone',
                'reason' => 'Provider desabilitado administrativamente.',
                'healthy' => false,
            ];
        }

        if ($healthFailed && $hasUnavailableSignal && $failureAttempts > 0) {
            return [
                'label' => 'unavailable',
                'tone' => 'rose',
                'reason' => 'Healthcheck ruim com indisponibilidade recente do provider.',
                'healthy' => false,
            ];
        }

        if (
            $healthFailed
            || $failureRate >= 60
            || $fallbackScheduledTotal > 0
            || $fallbackExecutedTotal > 0
            || $retryScheduledTotal >= 3
        ) {
            return [
                'label' => 'unstable',
                'tone' => 'amber',
                'reason' => 'Ha sinais recentes de retry, fallback ou degradacao operacional.',
                'healthy' => false,
            ];
        }

        if ($failureAttempts > 0 || $retryScheduledTotal > 0 || $hasTimeoutSignal || $hasRateLimitSignal || $hasTransientSignal) {
            return [
                'label' => 'degraded',
                'tone' => 'amber',
                'reason' => 'Ha falhas recentes, mas o provider segue operando.',
                'healthy' => true,
            ];
        }

        return [
            'label' => 'healthy',
            'tone' => 'emerald',
            'reason' => 'Sem sinais relevantes de falha na janela observada.',
            'healthy' => true,
        ];
    }

    /**
     * @param  array<int, array{code:string,total:int}>  $signalTotals
     */
    private function signalPresent(array $signalTotals, string $code): bool
    {
        foreach ($signalTotals as $signal) {
            if (($signal['code'] ?? null) === $code && (int) ($signal['total'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeFallback(mixed $fallback): ?array
    {
        if (! is_array($fallback)) {
            return null;
        }

        return $this->sanitizer->sanitize($fallback);
    }
}
