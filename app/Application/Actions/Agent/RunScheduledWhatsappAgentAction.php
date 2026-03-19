<?php

namespace App\Application\Actions\Agent;

use App\Application\Actions\Observability\RecordWhatsappSchedulerEventAction;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class RunScheduledWhatsappAgentAction
{
    public function __construct(
        private readonly AnalyzeWhatsappOperationsAgentAction $analyzeAgent,
        private readonly RecordWhatsappSchedulerEventAction $recordSchedulerEvent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        $startedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $schedulerRunId = (string) Str::ulid();

        $this->recordSchedulerEvent->execute(
            schedulerType: 'agent',
            eventName: 'whatsapp.agent.scheduler_run_started',
            payload: [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'started_at' => $startedAt->toIso8601String(),
            ],
            correlationId: $schedulerRunId,
            result: [
                'status' => 'started',
            ],
            idempotencyKey: sprintf('agent-scheduler-started:%s', $schedulerRunId),
            occurredAt: $startedAt,
        );

        try {
            $summary = $this->analyzeAgent->execute();
            $completedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
            $durationMs = $completedAt->diffInMilliseconds($startedAt);

            $this->recordSchedulerEvent->execute(
                schedulerType: 'agent',
                eventName: 'whatsapp.agent.scheduler_run_completed',
                payload: [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'started_at' => $startedAt->toIso8601String(),
                    'completed_at' => $completedAt->toIso8601String(),
                    'duration_ms' => $durationMs,
                    'agent_run_id' => $summary['agent_run_id'] ?? null,
                    'insights_created' => (int) ($summary['insights_created'] ?? 0),
                    'insights_refreshed' => (int) ($summary['insights_refreshed'] ?? 0),
                    'insights_resolved' => (int) ($summary['insights_resolved'] ?? 0),
                    'insights_ignored' => (int) ($summary['insights_ignored'] ?? 0),
                    'safe_actions_executed' => (int) ($summary['safe_actions_executed'] ?? 0),
                    'active_insights_total' => (int) ($summary['active_insights_total'] ?? 0),
                    'skipped_due_to_lock' => (bool) ($summary['skipped_due_to_lock'] ?? false),
                    'lock_key' => $summary['lock_key'] ?? null,
                ],
                correlationId: $schedulerRunId,
                result: [
                    'status' => (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'skipped_due_to_lock' : 'completed',
                ],
                idempotencyKey: sprintf('agent-scheduler-completed:%s', $schedulerRunId),
                occurredAt: $completedAt,
            );

            return array_merge($summary, [
                'scheduler_run_id' => $schedulerRunId,
                'scheduler_status' => (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'skipped_due_to_lock' : 'completed',
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $throwable) {
            $failedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));

            $this->recordSchedulerEvent->execute(
                schedulerType: 'agent',
                eventName: 'whatsapp.agent.scheduler_run_failed',
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
                idempotencyKey: sprintf('agent-scheduler-failed:%s', $schedulerRunId),
                occurredAt: $failedAt,
            );

            throw $throwable;
        }
    }
}
