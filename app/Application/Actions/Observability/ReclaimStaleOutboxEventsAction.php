<?php

namespace App\Application\Actions\Observability;

use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReclaimStaleOutboxEventsAction
{
    private const MINIMUM_SAFE_STALE_AFTER_SECONDS = 121;

    public function __construct(
        private readonly RecordOutboxLifecycleAuditAction $recordAudit,
    ) {
    }

    /**
     * @return array{enabled:bool,stale_after_seconds:int,max_attempts:int,backoff_seconds:int,reclaimed:int,reconciled:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    public function execute(int $limit = 50): array
    {
        $summary = $this->emptySummary();

        if (! $summary['enabled']) {
            return $summary;
        }

        $candidateIds = OutboxEvent::query()
            ->where('status', 'processing')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $this->staleCutoff())
            ->orderBy('reserved_at')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($candidateIds as $candidateId) {
            $result = $this->reclaimById((string) $candidateId);
            $decision = $result['decision'] ?? 'skipped';

            if (! array_key_exists($decision, $summary)) {
                $decision = 'skipped';
            }

            $summary[$decision]++;
            $summary['items'][] = $result;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function reclaimById(string $outboxEventId): array
    {
        if (! $this->enabled()) {
            return [
                'decision' => 'skipped',
                'outbox_event_id' => $outboxEventId,
                'reason' => 'reclaim_disabled',
            ];
        }

        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEventId) {
            /** @var OutboxEvent|null $outboxEvent */
            $outboxEvent = OutboxEvent::query()
                ->with(['eventLog', 'message'])
                ->lockForUpdate()
                ->find($outboxEventId);

            if ($outboxEvent === null) {
                return [
                    'decision' => 'skipped',
                    'outbox_event_id' => $outboxEventId,
                    'reason' => 'not_found',
                ];
            }

            $staleAgeSeconds = $this->staleAgeSeconds($outboxEvent);

            if (
                $outboxEvent->status !== 'processing'
                || $outboxEvent->reserved_at === null
                || $outboxEvent->reserved_at->gt($this->staleCutoff())
            ) {
                return [
                    'decision' => 'skipped',
                    'outbox_event_id' => $outboxEvent->id,
                    'event_name' => $outboxEvent->event_name,
                    'reason' => 'not_stale_anymore',
                    'stale_age_seconds' => $staleAgeSeconds,
                ];
            }

            $currentAttempt = IntegrationAttempt::query()
                ->where('outbox_event_id', $outboxEvent->id)
                ->where('attempt_count', $outboxEvent->attempt_count)
                ->latest('created_at')
                ->first();

            if ($this->shouldReconcileProcessed($outboxEvent, $currentAttempt)) {
                return $this->markProcessedFromEvidence($outboxEvent, $currentAttempt, $staleAgeSeconds);
            }

            if ($this->shouldMirrorTerminalFailure($currentAttempt)) {
                return $this->markFailedTerminal(
                    $outboxEvent,
                    reason: 'terminal_failure_evidence_recorded',
                    staleAgeSeconds: $staleAgeSeconds,
                    currentAttempt: $currentAttempt,
                    blockedByMaxReclaims: false,
                );
            }

            if ($this->shouldMirrorRetry($currentAttempt)) {
                return $this->scheduleRetry(
                    $outboxEvent,
                    reason: 'retryable_failure_evidence_recorded',
                    staleAgeSeconds: $staleAgeSeconds,
                    nextAvailableAt: $currentAttempt?->next_retry_at,
                    currentAttempt: $currentAttempt,
                );
            }

            if ($this->reclaimLimitExceeded($outboxEvent)) {
                return $this->markFailedTerminal(
                    $outboxEvent,
                    reason: 'max_reclaim_attempts_exceeded',
                    staleAgeSeconds: $staleAgeSeconds,
                    currentAttempt: $currentAttempt,
                    blockedByMaxReclaims: true,
                );
            }

            if ($this->unsafeToReopenAutomatically($outboxEvent, $currentAttempt)) {
                return $this->markFailedTerminal(
                    $outboxEvent,
                    reason: 'automatic_reopen_unsafe_due_to_inflight_dispatch',
                    staleAgeSeconds: $staleAgeSeconds,
                    currentAttempt: $currentAttempt,
                    blockedByMaxReclaims: false,
                );
            }

            return $this->scheduleRetry(
                $outboxEvent,
                reason: 'stale_processing_reclaimed',
                staleAgeSeconds: $staleAgeSeconds,
                nextAvailableAt: null,
                currentAttempt: $currentAttempt,
            );
        });
    }

    /**
     * @return array{enabled:bool,stale_after_seconds:int,max_attempts:int,backoff_seconds:int,reclaimed:int,reconciled:int,failed:int,skipped:int,items:list<array<string,mixed>>}
     */
    private function emptySummary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'stale_after_seconds' => $this->staleAfterSeconds(),
            'max_attempts' => $this->maxReclaimAttempts(),
            'backoff_seconds' => $this->backoffSeconds(),
            'reclaimed' => 0,
            'reconciled' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('observability.outbox.reclaim.enabled', true);
    }

    private function staleAfterSeconds(): int
    {
        return max(
            self::MINIMUM_SAFE_STALE_AFTER_SECONDS,
            (int) config('observability.outbox.reclaim.stale_after_seconds', 300),
        );
    }

    private function maxReclaimAttempts(): int
    {
        return max(1, (int) config('observability.outbox.reclaim.max_attempts', 3));
    }

    private function backoffSeconds(): int
    {
        return max(1, (int) config('observability.outbox.reclaim.backoff_seconds', 30));
    }

    private function staleCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds($this->staleAfterSeconds());
    }

    private function staleAgeSeconds(OutboxEvent $outboxEvent): int
    {
        if ($outboxEvent->reserved_at === null) {
            return 0;
        }

        return (int) $outboxEvent->reserved_at->diffInSeconds(now());
    }

    private function reclaimLimitExceeded(OutboxEvent $outboxEvent): bool
    {
        return (int) $outboxEvent->reclaim_count >= $this->maxReclaimAttempts();
    }

    private function shouldReconcileProcessed(OutboxEvent $outboxEvent, ?IntegrationAttempt $currentAttempt): bool
    {
        if (in_array($currentAttempt?->status, ['succeeded', 'duplicate_prevented'], true)) {
            return true;
        }

        if ($outboxEvent->event_name !== 'whatsapp.message.dispatch.requested') {
            return false;
        }

        $message = $outboxEvent->message;

        if ($message === null) {
            return false;
        }

        return (
            $message->external_message_id !== null
            && in_array($message->status, [
                WhatsappMessageStatus::Dispatched->value,
                WhatsappMessageStatus::Sent->value,
                WhatsappMessageStatus::Delivered->value,
                WhatsappMessageStatus::Read->value,
            ], true)
        ) || $message->status === 'duplicate_prevented';
    }

    private function shouldMirrorTerminalFailure(?IntegrationAttempt $currentAttempt): bool
    {
        return $currentAttempt !== null
            && $currentAttempt->status === 'failed'
            && ! (bool) $currentAttempt->retryable;
    }

    private function shouldMirrorRetry(?IntegrationAttempt $currentAttempt): bool
    {
        return $currentAttempt !== null
            && $currentAttempt->status === 'retry_scheduled'
            && (bool) $currentAttempt->retryable;
    }

    private function unsafeToReopenAutomatically(OutboxEvent $outboxEvent, ?IntegrationAttempt $currentAttempt): bool
    {
        return $outboxEvent->event_name === 'whatsapp.message.dispatch.requested'
            && $currentAttempt !== null
            && $currentAttempt->status === 'processing';
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRetry(
        OutboxEvent $outboxEvent,
        string $reason,
        int $staleAgeSeconds,
        ?\DateTimeInterface $nextAvailableAt,
        ?IntegrationAttempt $currentAttempt,
    ): array {
        $reclaimCountBefore = (int) $outboxEvent->reclaim_count;
        $nextAvailableAt ??= now()->addSeconds($this->backoffSeconds());
        $failureReason = match ($reason) {
            'retryable_failure_evidence_recorded' => $currentAttempt?->failure_reason ?: 'Falha retryable preservada durante reclaim.',
            default => 'Evento stale em processing foi recolocado com seguranca para retry.',
        };

        $outboxEvent->forceFill([
            'status' => 'retry_scheduled',
            'available_at' => $nextAvailableAt,
            'reserved_at' => null,
            'failed_at' => null,
            'failure_reason' => $failureReason,
            'reclaim_count' => $reclaimCountBefore + 1,
            'last_reclaimed_at' => now(),
            'last_reclaim_reason' => $reason,
        ])->save();

        $outboxEvent->eventLog?->forceFill([
            'status' => 'retry_scheduled',
            'result_json' => [
                'decision' => 'reclaimed',
                'reason' => $reason,
                'stale_age_seconds' => $staleAgeSeconds,
                'next_retry_at' => $nextAvailableAt->format(DATE_ATOM),
                'reclaim_count_before' => $reclaimCountBefore,
                'reclaim_count_after' => $outboxEvent->reclaim_count,
            ],
            'failure_reason' => $failureReason,
            'failed_at' => null,
        ])->save();

        $this->recordAudit->execute(
            $outboxEvent,
            eventName: 'outbox.event.reclaimed',
            payload: [
                'outbox_event_id' => $outboxEvent->id,
                'event_name' => $outboxEvent->event_name,
                'stale_age_seconds' => $staleAgeSeconds,
                'reclaim_count_before' => $reclaimCountBefore,
                'reclaim_count_after' => $outboxEvent->reclaim_count,
                'reason' => $reason,
            ],
            result: [
                'decision' => 'reclaimed',
                'next_available_at' => $nextAvailableAt->format(DATE_ATOM),
                'blocked_by_max_reclaims' => false,
            ],
            context: [
                'source' => 'outbox_reclaimer',
                'message_id' => $outboxEvent->message_id,
                'current_attempt_status' => $currentAttempt?->status,
            ],
        );

        return [
            'decision' => 'reclaimed',
            'outbox_event_id' => $outboxEvent->id,
            'event_name' => $outboxEvent->event_name,
            'reason' => $reason,
            'stale_age_seconds' => $staleAgeSeconds,
            'reclaim_count_before' => $reclaimCountBefore,
            'reclaim_count_after' => $outboxEvent->reclaim_count,
            'next_available_at' => $nextAvailableAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markProcessedFromEvidence(
        OutboxEvent $outboxEvent,
        ?IntegrationAttempt $currentAttempt,
        int $staleAgeSeconds,
    ): array {
        $reason = 'dispatch_already_completed';

        $outboxEvent->forceFill([
            'status' => 'processed',
            'processed_at' => now(),
            'reserved_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'last_reclaimed_at' => now(),
            'last_reclaim_reason' => $reason,
        ])->save();

        $outboxEvent->eventLog?->forceFill([
            'status' => 'processed',
            'processed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
            'result_json' => [
                'decision' => 'reconciled',
                'reason' => $reason,
                'stale_age_seconds' => $staleAgeSeconds,
            ],
        ])->save();

        $this->recordAudit->execute(
            $outboxEvent,
            eventName: 'outbox.event.reconciled',
            payload: [
                'outbox_event_id' => $outboxEvent->id,
                'event_name' => $outboxEvent->event_name,
                'stale_age_seconds' => $staleAgeSeconds,
                'reason' => $reason,
            ],
            result: [
                'decision' => 'reconciled',
                'provider_message_id' => $currentAttempt?->provider_message_id ?: $outboxEvent->message?->external_message_id,
                'attempt_status' => $currentAttempt?->status,
            ],
            context: [
                'source' => 'outbox_reclaimer',
                'message_id' => $outboxEvent->message_id,
            ],
        );

        return [
            'decision' => 'reconciled',
            'outbox_event_id' => $outboxEvent->id,
            'event_name' => $outboxEvent->event_name,
            'reason' => $reason,
            'stale_age_seconds' => $staleAgeSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markFailedTerminal(
        OutboxEvent $outboxEvent,
        string $reason,
        int $staleAgeSeconds,
        ?IntegrationAttempt $currentAttempt,
        bool $blockedByMaxReclaims,
    ): array {
        $failureReason = match ($reason) {
            'max_reclaim_attempts_exceeded' => 'O evento stale excedeu o limite maximo de reclaim automatico.',
            'terminal_failure_evidence_recorded' => $currentAttempt?->failure_reason ?: 'Falha terminal previamente registrada e reconciliada.',
            default => 'Reclaim automatico bloqueado por risco de envio duplo; revisao manual obrigatoria.',
        };

        $outboxEvent->forceFill([
            'status' => 'failed',
            'reserved_at' => null,
            'failed_at' => now(),
            'failure_reason' => $failureReason,
            'last_reclaimed_at' => now(),
            'last_reclaim_reason' => $reason,
        ])->save();

        $outboxEvent->eventLog?->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $failureReason,
            'result_json' => [
                'decision' => 'failed',
                'reason' => $reason,
                'stale_age_seconds' => $staleAgeSeconds,
                'blocked_by_max_reclaims' => $blockedByMaxReclaims,
            ],
        ])->save();

        $this->recordAudit->execute(
            $outboxEvent,
            eventName: 'outbox.event.reclaim.blocked',
            payload: [
                'outbox_event_id' => $outboxEvent->id,
                'event_name' => $outboxEvent->event_name,
                'stale_age_seconds' => $staleAgeSeconds,
                'reason' => $reason,
                'reclaim_count' => $outboxEvent->reclaim_count,
            ],
            result: [
                'decision' => 'failed',
                'blocked_by_max_reclaims' => $blockedByMaxReclaims,
                'attempt_status' => $currentAttempt?->status,
            ],
            context: [
                'source' => 'outbox_reclaimer',
                'message_id' => $outboxEvent->message_id,
            ],
        );

        return [
            'decision' => 'failed',
            'outbox_event_id' => $outboxEvent->id,
            'event_name' => $outboxEvent->event_name,
            'reason' => $reason,
            'stale_age_seconds' => $staleAgeSeconds,
            'blocked_by_max_reclaims' => $blockedByMaxReclaims,
        ];
    }
}
