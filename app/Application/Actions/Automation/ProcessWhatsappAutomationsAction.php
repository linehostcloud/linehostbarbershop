<?php

namespace App\Application\Actions\Automation;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Client\Models\Client;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use Carbon\CarbonImmutable;
use Throwable;

class ProcessWhatsappAutomationsAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
        private readonly QueueWhatsappAutomationTargetAction $queueAutomationTarget,
        private readonly RecordWhatsappAutomationEventAction $recordEvent,
        private readonly TenantExecutionLockManager $lockManager,
    ) {
    }

    /**
     * @param  list<string>|null  $types
     * @return array{
     *     processed_automations:int,
     *     candidates_found:int,
     *     messages_queued:int,
     *     skipped_total:int,
     *     failed_total:int,
     *     runs:list<array<string, mixed>>
     *     skipped_due_to_lock:bool,
     *     lock_key:string
     * }
     */
    public function execute(?array $types = null, ?int $limit = null): array
    {
        $lockTtlSeconds = max(30, (int) config('communication.whatsapp.execution_locks.automations_seconds', 300));
        $lock = $this->lockManager->executeForCurrentTenantConnection(
            operation: 'whatsapp_automations',
            seconds: $lockTtlSeconds,
            callback: fn (): array => $this->executeUnlocked($types, $limit),
        );

        if (! $lock['acquired']) {
            return array_merge($this->emptyOverallSummary(), [
                'skipped_due_to_lock' => true,
                'lock_key' => $lock['lock_key'],
            ]);
        }

        return array_merge((array) $lock['result'], [
            'skipped_due_to_lock' => false,
            'lock_key' => $lock['lock_key'],
        ]);
    }

    /**
     * @param  list<string>|null  $types
     * @return array{
     *     processed_automations:int,
     *     candidates_found:int,
     *     messages_queued:int,
     *     skipped_total:int,
     *     failed_total:int,
     *     runs:list<array<string, mixed>>
     * }
     */
    private function executeUnlocked(?array $types = null, ?int $limit = null): array
    {
        $supportedTypes = $this->supportedTypes($types);
        $limit ??= max(1, (int) config('communication.whatsapp.automations.default_processing_limit', 100));
        $this->ensureDefaults->execute();

        $automations = Automation::query()
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->whereIn('trigger_event', array_map(static fn (WhatsappAutomationType $type): string => $type->value, $supportedTypes))
            ->orderBy('priority')
            ->orderBy('trigger_event')
            ->get();

        $summary = $this->emptyOverallSummary();

        foreach ($automations as $automation) {
            $runSummary = $this->processAutomation($automation, $limit);

            $summary['processed_automations']++;
            $summary['candidates_found'] += (int) $runSummary['candidates_found'];
            $summary['messages_queued'] += (int) $runSummary['messages_queued'];
            $summary['skipped_total'] += (int) $runSummary['skipped_total'];
            $summary['failed_total'] += (int) $runSummary['failed_total'];
            $summary['runs'][] = $runSummary;
        }

        return $summary;
    }

    /**
     * @return array{
     *     processed_automations:int,
     *     candidates_found:int,
     *     messages_queued:int,
     *     skipped_total:int,
     *     failed_total:int,
     *     runs:list<array<string, mixed>>
     * }
     */
    private function emptyOverallSummary(): array
    {
        return [
            'processed_automations' => 0,
            'candidates_found' => 0,
            'messages_queued' => 0,
            'skipped_total' => 0,
            'failed_total' => 0,
            'runs' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processAutomation(Automation $automation, int $limit): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        [$windowStartedAt, $windowEndedAt] = $this->automationWindow($automation, $now);
        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'automation_type' => (string) $automation->trigger_event,
            'channel' => 'whatsapp',
            'status' => 'running',
            'window_started_at' => $windowStartedAt,
            'window_ended_at' => $windowEndedAt,
            'started_at' => $now,
            'run_context_json' => [
                'automation_name' => $automation->name,
                'limit' => $limit,
                'cooldown_hours' => (int) $automation->cooldown_hours,
            ],
        ]);

        try {
            $summary = match (WhatsappAutomationType::from((string) $automation->trigger_event)) {
                WhatsappAutomationType::AppointmentReminder => $this->processAppointmentReminder($automation, $run, $now, $limit),
                WhatsappAutomationType::InactiveClientReactivation => $this->processInactiveClientReactivation($automation, $run, $now, $limit),
            };

            $run->forceFill([
                'status' => 'completed',
                'candidates_found' => $summary['candidates_found'],
                'messages_queued' => $summary['messages_queued'],
                'skipped_total' => $summary['skipped_total'],
                'failed_total' => $summary['failed_total'],
                'completed_at' => now(),
                'result_json' => [
                    'skip_reasons' => $summary['skip_reasons'],
                    'failed_reasons' => $summary['failed_reasons'],
                ],
                'failure_reason' => null,
            ])->save();

            $automation->forceFill([
                'last_executed_at' => now(),
            ])->save();

            $this->recordEvent->execute(
                automation: $automation,
                run: $run,
                eventName: 'whatsapp.automation.run.completed',
                payload: [
                    'automation_run_id' => $run->id,
                    'automation_type' => $automation->trigger_event,
                    'automation_name' => $automation->name,
                    'candidates_found' => $summary['candidates_found'],
                    'messages_queued' => $summary['messages_queued'],
                    'skipped_total' => $summary['skipped_total'],
                    'failed_total' => $summary['failed_total'],
                    'skip_reasons' => $summary['skip_reasons'],
                    'failed_reasons' => $summary['failed_reasons'],
                ],
                context: [
                    'window_started_at' => $windowStartedAt->toIso8601String(),
                    'window_ended_at' => $windowEndedAt->toIso8601String(),
                ],
                result: [
                    'status' => 'completed',
                ],
                idempotencyKey: sprintf('automation-run-completed:%s', $run->id),
                occurredAt: now(),
            );

            return [
                'automation_id' => $automation->id,
                'automation_run_id' => $run->id,
                'automation_type' => $automation->trigger_event,
                ...$summary,
            ];
        } catch (Throwable $throwable) {
            $run->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
                'completed_at' => now(),
            ])->save();

            $this->recordEvent->execute(
                automation: $automation,
                run: $run,
                eventName: 'whatsapp.automation.run.failed',
                payload: [
                    'automation_run_id' => $run->id,
                    'automation_type' => $automation->trigger_event,
                    'automation_name' => $automation->name,
                    'error_message' => $throwable->getMessage(),
                ],
                result: [
                    'status' => 'failed',
                ],
                idempotencyKey: sprintf('automation-run-failed:%s', $run->id),
                occurredAt: now(),
            );

            throw $throwable;
        }
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function automationWindow(Automation $automation, CarbonImmutable $now): array
    {
        return match (WhatsappAutomationType::from((string) $automation->trigger_event)) {
            WhatsappAutomationType::AppointmentReminder => [
                $now
                    ->addMinutes((int) data_get($automation->conditions_json, 'lead_time_minutes', 1440))
                    ->subMinutes((int) data_get($automation->conditions_json, 'selection_tolerance_minutes', 10)),
                $now
                    ->addMinutes((int) data_get($automation->conditions_json, 'lead_time_minutes', 1440))
                    ->addMinutes((int) data_get($automation->conditions_json, 'selection_tolerance_minutes', 10)),
            ],
            WhatsappAutomationType::InactiveClientReactivation => [
                $now->subDays((int) data_get($automation->conditions_json, 'inactivity_days', 45)),
                $now,
            ],
        };
    }

    /**
     * @return array{candidates_found:int,messages_queued:int,skipped_total:int,failed_total:int,skip_reasons:array<string,int>,failed_reasons:array<string,int>}
     */
    private function processAppointmentReminder(
        Automation $automation,
        AutomationRun $run,
        CarbonImmutable $now,
        int $limit,
    ): array {
        $summary = $this->emptyExecutionSummary();
        $candidates = $this->discoverCandidates->execute($automation, $now, $limit);
        $summary['candidates_found'] = $candidates->count();

        foreach ($candidates as $candidate) {
            if (! $candidate->isEligible()) {
                $skipReason = $candidate->skipReason ?? 'not_eligible';
                $this->recordSkippedTarget(
                    automation: $automation,
                    run: $run,
                    targetType: $candidate->targetType,
                    targetId: $candidate->targetId,
                    context: $candidate->context,
                    skipReason: $skipReason,
                    triggerReason: $candidate->triggerReason,
                );
                $summary['skipped_total']++;
                $summary['skip_reasons'][$skipReason] = (int) (($summary['skip_reasons'][$skipReason] ?? 0) + 1);

                continue;
            }

            $result = $this->queueAutomationMessage(
                automation: $automation,
                run: $run,
                targetType: $candidate->targetType,
                targetId: $candidate->targetId,
                triggerReason: $candidate->triggerReason,
                client: $candidate->client,
                appointment: $candidate->appointment,
                context: $candidate->context,
            );

            if ($result['queued']) {
                $summary['messages_queued']++;
                continue;
            }

            $summary['failed_total']++;
            $summary['failed_reasons'][$result['failure_reason']] = (int) (($summary['failed_reasons'][$result['failure_reason']] ?? 0) + 1);
        }

        return $summary;
    }

    /**
     * @return array{candidates_found:int,messages_queued:int,skipped_total:int,failed_total:int,skip_reasons:array<string,int>,failed_reasons:array<string,int>}
     */
    private function processInactiveClientReactivation(
        Automation $automation,
        AutomationRun $run,
        CarbonImmutable $now,
        int $limit,
    ): array {
        $summary = $this->emptyExecutionSummary();
        $candidates = $this->discoverCandidates->execute($automation, $now, $limit);
        $summary['candidates_found'] = $candidates->count();

        foreach ($candidates as $candidate) {
            if (! $candidate->isEligible()) {
                $skipReason = $candidate->skipReason ?? 'not_eligible';
                $this->recordSkippedTarget(
                    automation: $automation,
                    run: $run,
                    targetType: $candidate->targetType,
                    targetId: $candidate->targetId,
                    context: $candidate->context,
                    skipReason: $skipReason,
                    triggerReason: $candidate->triggerReason,
                );
                $summary['skipped_total']++;
                $summary['skip_reasons'][$skipReason] = (int) (($summary['skip_reasons'][$skipReason] ?? 0) + 1);

                continue;
            }

            $result = $this->queueAutomationMessage(
                automation: $automation,
                run: $run,
                targetType: $candidate->targetType,
                targetId: $candidate->targetId,
                triggerReason: $candidate->triggerReason,
                client: $candidate->client,
                appointment: $candidate->appointment,
                context: $candidate->context,
            );

            if ($result['queued']) {
                $summary['messages_queued']++;
                continue;
            }

            $summary['failed_total']++;
            $summary['failed_reasons'][$result['failure_reason']] = (int) (($summary['failed_reasons'][$result['failure_reason']] ?? 0) + 1);
        }

        return $summary;
    }

    /**
     * @return array{queued:bool,failure_reason:string}
     */
    private function queueAutomationMessage(
        Automation $automation,
        AutomationRun $run,
        string $targetType,
        string $targetId,
        string $triggerReason,
        ?Client $client,
        ?Appointment $appointment,
        array $context,
    ): array {
        return $this->queueAutomationTarget->execute(
            automation: $automation,
            run: $run,
            targetType: $targetType,
            targetId: $targetId,
            triggerReason: $triggerReason,
            client: $client,
            appointment: $appointment,
            context: $context,
            triggerSource: 'automation',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordSkippedTarget(
        Automation $automation,
        AutomationRun $run,
        string $targetType,
        string $targetId,
        array $context,
        string $skipReason,
        string $triggerReason,
    ): void {
        AutomationRunTarget::query()->create([
            'automation_run_id' => $run->id,
            'automation_id' => $automation->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'client_id' => $context['client_id'] ?? null,
            'appointment_id' => $context['appointment_id'] ?? null,
            'status' => 'skipped',
            'trigger_reason' => $triggerReason,
            'skip_reason' => $skipReason,
            'context_json' => $context,
        ]);
    }

    /**
     * @return array{candidates_found:int,messages_queued:int,skipped_total:int,failed_total:int,skip_reasons:array<string,int>,failed_reasons:array<string,int>}
     */
    private function emptyExecutionSummary(): array
    {
        return [
            'candidates_found' => 0,
            'messages_queued' => 0,
            'skipped_total' => 0,
            'failed_total' => 0,
            'skip_reasons' => [],
            'failed_reasons' => [],
        ];
    }

    /**
     * @param  list<string>|null  $types
     * @return list<WhatsappAutomationType>
     */
    private function supportedTypes(?array $types): array
    {
        if ($types === null || $types === []) {
            return WhatsappAutomationType::cases();
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?WhatsappAutomationType => is_string($value)
                ? WhatsappAutomationType::tryFrom($value)
                : null,
            $types,
        )));
    }
}
