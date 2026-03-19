<?php

namespace App\Application\Actions\Automation;

use App\Application\Actions\Observability\RecordWhatsappSchedulerEventAction;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class RunScheduledWhatsappAutomationsAction
{
    public function __construct(
        private readonly ProcessWhatsappAutomationsAction $processAutomations,
        private readonly RecordWhatsappSchedulerEventAction $recordSchedulerEvent,
    ) {
    }

    /**
     * @param  list<string>|null  $types
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant, ?array $types = null, ?int $limit = null): array
    {
        $startedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $schedulerRunId = (string) Str::ulid();

        $this->recordSchedulerEvent->execute(
            schedulerType: 'automations',
            eventName: 'whatsapp.automation.scheduler_run_started',
            payload: [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'types' => $types,
                'limit' => $limit,
                'started_at' => $startedAt->toIso8601String(),
            ],
            correlationId: $schedulerRunId,
            result: [
                'status' => 'started',
            ],
            idempotencyKey: sprintf('automation-scheduler-started:%s', $schedulerRunId),
            occurredAt: $startedAt,
        );

        try {
            $summary = $this->processAutomations->execute($types, $limit);
            $completedAt = CarbonImmutable::now(config('app.timezone', 'UTC'));
            $durationMs = $completedAt->diffInMilliseconds($startedAt);

            $this->recordSchedulerEvent->execute(
                schedulerType: 'automations',
                eventName: 'whatsapp.automation.scheduler_run_completed',
                payload: [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'types' => $types,
                    'limit' => $limit,
                    'started_at' => $startedAt->toIso8601String(),
                    'completed_at' => $completedAt->toIso8601String(),
                    'duration_ms' => $durationMs,
                    'processed_automations' => (int) ($summary['processed_automations'] ?? 0),
                    'candidates_found' => (int) ($summary['candidates_found'] ?? 0),
                    'messages_queued' => (int) ($summary['messages_queued'] ?? 0),
                    'skipped_total' => (int) ($summary['skipped_total'] ?? 0),
                    'failed_total' => (int) ($summary['failed_total'] ?? 0),
                    'skipped_due_to_lock' => (bool) ($summary['skipped_due_to_lock'] ?? false),
                    'lock_key' => $summary['lock_key'] ?? null,
                ],
                correlationId: $schedulerRunId,
                result: [
                    'status' => (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'skipped_due_to_lock' : 'completed',
                ],
                idempotencyKey: sprintf('automation-scheduler-completed:%s', $schedulerRunId),
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
                schedulerType: 'automations',
                eventName: 'whatsapp.automation.scheduler_run_failed',
                payload: [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'types' => $types,
                    'limit' => $limit,
                    'started_at' => $startedAt->toIso8601String(),
                    'failed_at' => $failedAt->toIso8601String(),
                    'duration_ms' => $failedAt->diffInMilliseconds($startedAt),
                    'error_message' => $throwable->getMessage(),
                ],
                correlationId: $schedulerRunId,
                result: [
                    'status' => 'failed',
                ],
                idempotencyKey: sprintf('automation-scheduler-failed:%s', $schedulerRunId),
                occurredAt: $failedAt,
            );

            throw $throwable;
        }
    }
}
