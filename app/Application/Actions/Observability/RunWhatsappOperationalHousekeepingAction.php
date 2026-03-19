<?php

namespace App\Application\Actions\Observability;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Automation\Models\AutomationRun;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class RunWhatsappOperationalHousekeepingAction
{
    public function __construct(
        private readonly ReclaimStaleOutboxEventsAction $reclaimStaleOutboxEvents,
        private readonly RecordWhatsappSchedulerEventAction $recordSchedulerEvent,
        private readonly TenantExecutionLockManager $lockManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant, ?int $limit = null): array
    {
        $startedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $schedulerRunId = (string) Str::ulid();
        $limit ??= max(1, (int) config('observability.whatsapp_housekeeping.default_batch_size', 200));

        $this->recordSchedulerEvent->execute(
            schedulerType: 'housekeeping',
            eventName: 'whatsapp.housekeeping.run_started',
            payload: [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'started_at' => $startedAt->toIso8601String(),
                'limit' => $limit,
            ],
            correlationId: $schedulerRunId,
            result: [
                'status' => 'started',
            ],
            idempotencyKey: sprintf('housekeeping-started:%s', $schedulerRunId),
            occurredAt: $startedAt,
        );

        try {
            $lockTtlSeconds = max(30, (int) config('communication.whatsapp.execution_locks.housekeeping_seconds', 600));
            $lock = $this->lockManager->executeForCurrentTenantConnection(
                operation: 'whatsapp_housekeeping',
                seconds: $lockTtlSeconds,
                callback: fn (): array => $this->executeUnlocked($limit),
            );

            if (! $lock['acquired']) {
                $completedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
                $summary = $this->emptySummary();
                $summary['skipped_due_to_lock'] = true;
                $summary['lock_key'] = $lock['lock_key'];

                $this->recordSchedulerEvent->execute(
                    schedulerType: 'housekeeping',
                    eventName: 'whatsapp.housekeeping.run_completed',
                    payload: array_merge($summary, [
                        'tenant_id' => $tenant->id,
                        'tenant_slug' => $tenant->slug,
                        'started_at' => $startedAt->toIso8601String(),
                        'completed_at' => $completedAt->toIso8601String(),
                        'duration_ms' => $completedAt->diffInMilliseconds($startedAt),
                    ]),
                    correlationId: $schedulerRunId,
                    result: [
                        'status' => 'skipped_due_to_lock',
                    ],
                    idempotencyKey: sprintf('housekeeping-completed:%s', $schedulerRunId),
                    occurredAt: $completedAt,
                );

                return array_merge($summary, [
                    'scheduler_run_id' => $schedulerRunId,
                    'scheduler_status' => 'skipped_due_to_lock',
                    'duration_ms' => $completedAt->diffInMilliseconds($startedAt),
                ]);
            }

            $completedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
            $summary = array_merge((array) $lock['result'], [
                'skipped_due_to_lock' => false,
                'lock_key' => $lock['lock_key'],
            ]);

            $this->recordSchedulerEvent->execute(
                schedulerType: 'housekeeping',
                eventName: 'whatsapp.housekeeping.run_completed',
                payload: array_merge($summary, [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'started_at' => $startedAt->toIso8601String(),
                    'completed_at' => $completedAt->toIso8601String(),
                    'duration_ms' => $completedAt->diffInMilliseconds($startedAt),
                ]),
                correlationId: $schedulerRunId,
                result: [
                    'status' => 'completed',
                ],
                idempotencyKey: sprintf('housekeeping-completed:%s', $schedulerRunId),
                occurredAt: $completedAt,
            );

            return array_merge($summary, [
                'scheduler_run_id' => $schedulerRunId,
                'scheduler_status' => 'completed',
                'duration_ms' => $completedAt->diffInMilliseconds($startedAt),
            ]);
        } catch (Throwable $throwable) {
            $failedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));

            $this->recordSchedulerEvent->execute(
                schedulerType: 'housekeeping',
                eventName: 'whatsapp.housekeeping.run_failed',
                payload: [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'started_at' => $startedAt->toIso8601String(),
                    'failed_at' => $failedAt->toIso8601String(),
                    'duration_ms' => $failedAt->diffInMilliseconds($startedAt),
                    'error_message' => $throwable->getMessage(),
                ],
                correlationId: $schedulerRunId,
                result: [
                    'status' => 'failed',
                ],
                idempotencyKey: sprintf('housekeeping-failed:%s', $schedulerRunId),
                occurredAt: $failedAt,
            );

            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executeUnlocked(int $limit): array
    {
        $reclaimSummary = $this->reclaimStaleOutboxEvents->execute($limit);

        return [
            'reclaim' => Arr::only($reclaimSummary, [
                'enabled',
                'reclaimed',
                'reconciled',
                'failed',
                'skipped',
                'stale_after_seconds',
                'max_attempts',
                'backoff_seconds',
            ]),
            'pruned' => [
                'outbox_events' => $this->pruneOutboxEvents(),
                'automation_runs' => $this->pruneAutomationRuns(),
                'agent_runs' => $this->pruneAgentRuns(),
                'agent_insights' => $this->pruneAgentInsights(),
                'event_logs' => $this->pruneEventLogs(),
                'integration_attempts' => $this->pruneIntegrationAttempts(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'reclaim' => [
                'enabled' => (bool) config('observability.outbox.reclaim.enabled', true),
                'reclaimed' => 0,
                'reconciled' => 0,
                'failed' => 0,
                'skipped' => 0,
                'stale_after_seconds' => (int) config('observability.outbox.reclaim.stale_after_seconds', 300),
                'max_attempts' => (int) config('observability.outbox.reclaim.max_attempts', 3),
                'backoff_seconds' => (int) config('observability.outbox.reclaim.backoff_seconds', 30),
            ],
            'pruned' => [
                'outbox_events' => 0,
                'automation_runs' => 0,
                'agent_runs' => 0,
                'agent_insights' => 0,
                'event_logs' => 0,
                'integration_attempts' => 0,
            ],
        ];
    }

    private function pruneAutomationRuns(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.automation_runs_retain_days', 30)));

        return AutomationRun::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('completed_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('completed_at')
                            ->where('started_at', '<=', $cutoff);
                    });
            })
            ->delete();
    }

    private function pruneAgentRuns(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.agent_runs_retain_days', 30)));

        return AgentRun::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('completed_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('completed_at')
                            ->where('started_at', '<=', $cutoff);
                    });
            })
            ->delete();
    }

    private function pruneAgentInsights(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.agent_insights_retain_days', 45)));

        return AgentInsight::query()
            ->whereIn('status', ['resolved', 'ignored', 'executed'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('resolved_at', '<=', $cutoff)
                    ->orWhere('ignored_at', '<=', $cutoff)
                    ->orWhere('executed_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('resolved_at')
                            ->whereNull('ignored_at')
                            ->whereNull('executed_at')
                            ->where('last_detected_at', '<=', $cutoff);
                    });
            })
            ->delete();
    }

    private function pruneOutboxEvents(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.outbox_events_retain_days', 30)));

        return OutboxEvent::query()
            ->whereIn('status', ['processed', 'failed'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('processed_at', '<=', $cutoff)
                    ->orWhere('failed_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('processed_at')
                            ->whereNull('failed_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->delete();
    }

    private function pruneEventLogs(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.event_logs_retain_days', 60)));

        return EventLog::query()
            ->where('occurred_at', '<=', $cutoff)
            ->whereDoesntHave('outboxEvents', fn (Builder $query): Builder => $query->whereIn('status', ['pending', 'processing', 'retry_scheduled']))
            ->delete();
    }

    private function pruneIntegrationAttempts(): int
    {
        $cutoff = CarbonImmutable::now(config('app.timezone', 'UTC'))
            ->subDays(max(1, (int) config('observability.whatsapp_housekeeping.integration_attempts_retain_days', 45)));

        return IntegrationAttempt::query()
            ->whereIn('status', ['succeeded', 'failed', 'duplicate_prevented'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('completed_at', '<=', $cutoff)
                    ->orWhere('failed_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('completed_at')
                            ->whereNull('failed_at')
                            ->where('last_attempt_at', '<=', $cutoff);
                    });
            })
            ->delete();
    }
}
