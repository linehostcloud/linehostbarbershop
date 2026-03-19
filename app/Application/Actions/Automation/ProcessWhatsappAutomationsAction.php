<?php

namespace App\Application\Actions\Automation;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Order\Models\Order;
use App\Application\Actions\Communication\QueueWhatsappMessageAction;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ProcessWhatsappAutomationsAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly QueueWhatsappMessageAction $queueWhatsappMessage,
        private readonly RenderWhatsappAutomationMessageAction $renderMessage,
        private readonly RecordWhatsappAutomationEventAction $recordEvent,
        private readonly TenantContext $tenantContext,
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
     * }
     */
    public function execute(?array $types = null, ?int $limit = null): array
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

        $summary = [
            'processed_automations' => 0,
            'candidates_found' => 0,
            'messages_queued' => 0,
            'skipped_total' => 0,
            'failed_total' => 0,
            'runs' => [],
        ];

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
        $leadTimeMinutes = (int) data_get($automation->conditions_json, 'lead_time_minutes', 1440);
        $toleranceMinutes = (int) data_get($automation->conditions_json, 'selection_tolerance_minutes', config('communication.whatsapp.automations.selection_tolerance_minutes', 10));
        $windowStart = $now->addMinutes($leadTimeMinutes)->subMinutes($toleranceMinutes);
        $windowEnd = $now->addMinutes($leadTimeMinutes)->addMinutes($toleranceMinutes);
        $excludedStatuses = array_values(array_filter(
            (array) data_get($automation->conditions_json, 'excluded_statuses', ['canceled', 'no_show', 'completed']),
            'is_string',
        ));
        $overFetch = max($limit * 5, 25);
        $appointments = Appointment::query()
            ->with(['client', 'professional', 'primaryService'])
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->orderBy('starts_at')
            ->limit($overFetch)
            ->get();

        $summary = $this->emptyExecutionSummary();

        foreach ($appointments as $appointment) {
            if ($summary['candidates_found'] >= $limit) {
                break;
            }

            $summary['candidates_found']++;

            if (in_array((string) $appointment->status, $excludedStatuses, true) || $appointment->canceled_at !== null) {
                $this->recordSkippedTarget($automation, $run, 'appointment', (string) $appointment->id, [
                    'client_id' => $appointment->client_id,
                    'appointment_id' => $appointment->id,
                    'starts_at' => $appointment->starts_at?->toIso8601String(),
                ], 'appointment_not_eligible', 'appointment_due_soon');
                $summary['skipped_total']++;
                $summary['skip_reasons']['appointment_not_eligible'] = (int) (($summary['skip_reasons']['appointment_not_eligible'] ?? 0) + 1);

                continue;
            }

            if ($appointment->reminder_sent_at !== null) {
                $this->recordSkippedTarget($automation, $run, 'appointment', (string) $appointment->id, [
                    'client_id' => $appointment->client_id,
                    'appointment_id' => $appointment->id,
                    'starts_at' => $appointment->starts_at?->toIso8601String(),
                ], 'reminder_already_sent', 'appointment_due_soon');
                $summary['skipped_total']++;
                $summary['skip_reasons']['reminder_already_sent'] = (int) (($summary['skip_reasons']['reminder_already_sent'] ?? 0) + 1);

                continue;
            }

            if (($skipReason = $this->contactSkipReason($appointment->client, false)) !== null) {
                $this->recordSkippedTarget($automation, $run, 'appointment', (string) $appointment->id, [
                    'client_id' => $appointment->client_id,
                    'appointment_id' => $appointment->id,
                    'starts_at' => $appointment->starts_at?->toIso8601String(),
                ], $skipReason, 'appointment_due_soon');
                $summary['skipped_total']++;
                $summary['skip_reasons'][$skipReason] = (int) (($summary['skip_reasons'][$skipReason] ?? 0) + 1);

                continue;
            }

            if ($this->cooldownActive($automation, 'appointment', (string) $appointment->id, $now)) {
                $this->recordSkippedTarget($automation, $run, 'appointment', (string) $appointment->id, [
                    'client_id' => $appointment->client_id,
                    'appointment_id' => $appointment->id,
                    'starts_at' => $appointment->starts_at?->toIso8601String(),
                ], 'cooldown_active', 'appointment_due_soon');
                $summary['skipped_total']++;
                $summary['skip_reasons']['cooldown_active'] = (int) (($summary['skip_reasons']['cooldown_active'] ?? 0) + 1);

                continue;
            }

            $result = $this->queueAutomationMessage(
                automation: $automation,
                run: $run,
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                client: $appointment->client,
                appointment: $appointment,
                context: $this->appointmentAutomationContext($automation, $appointment, $now),
            );

            if ($result['queued']) {
                $summary['messages_queued']++;

                $appointment->forceFill([
                    'reminder_sent_at' => now(),
                    'confirmation_status' => 'reminder_queued',
                ])->save();

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
        $inactivityDays = (int) data_get($automation->conditions_json, 'inactivity_days', 45);
        $minimumCompletedVisits = max(1, (int) data_get($automation->conditions_json, 'minimum_completed_visits', 1));
        $overFetch = max($limit * 5, 50);
        $clients = $this->reactivationBaseQuery($now)
            ->limit($overFetch)
            ->get();

        $summary = $this->emptyExecutionSummary();

        foreach ($clients as $client) {
            if ($summary['candidates_found'] >= $limit) {
                break;
            }

            $summary['candidates_found']++;
            $lastEngagementAt = $this->clientLastEngagementAt($client);
            $completedVisits = $this->clientCompletedVisits($client);

            if ($lastEngagementAt === null) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                ], 'no_visit_history', 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons']['no_visit_history'] = (int) (($summary['skip_reasons']['no_visit_history'] ?? 0) + 1);

                continue;
            }

            if (($skipReason = $this->contactSkipReason($client, true)) !== null) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                    'last_engagement_at' => $lastEngagementAt->toIso8601String(),
                ], $skipReason, 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons'][$skipReason] = (int) (($summary['skip_reasons'][$skipReason] ?? 0) + 1);

                continue;
            }

            if ($completedVisits < $minimumCompletedVisits) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                    'completed_visits' => $completedVisits,
                ], 'insufficient_history', 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons']['insufficient_history'] = (int) (($summary['skip_reasons']['insufficient_history'] ?? 0) + 1);

                continue;
            }

            if ((int) ($client->future_appointments_count ?? 0) > 0) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                    'future_appointments_count' => (int) ($client->future_appointments_count ?? 0),
                ], 'future_appointment_exists', 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons']['future_appointment_exists'] = (int) (($summary['skip_reasons']['future_appointment_exists'] ?? 0) + 1);

                continue;
            }

            if ($lastEngagementAt->diffInDays($now) < $inactivityDays) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                    'last_engagement_at' => $lastEngagementAt->toIso8601String(),
                    'inactive_days' => $lastEngagementAt->diffInDays($now),
                ], 'not_inactive_enough', 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons']['not_inactive_enough'] = (int) (($summary['skip_reasons']['not_inactive_enough'] ?? 0) + 1);

                continue;
            }

            if ($this->cooldownActive($automation, 'client', (string) $client->id, $now)) {
                $this->recordSkippedTarget($automation, $run, 'client', (string) $client->id, [
                    'client_id' => $client->id,
                    'last_engagement_at' => $lastEngagementAt->toIso8601String(),
                ], 'cooldown_active', 'inactive_for_reactivation');
                $summary['skipped_total']++;
                $summary['skip_reasons']['cooldown_active'] = (int) (($summary['skip_reasons']['cooldown_active'] ?? 0) + 1);

                continue;
            }

            $result = $this->queueAutomationMessage(
                automation: $automation,
                run: $run,
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
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
        $target = AutomationRunTarget::query()->create([
            'id' => (string) Str::ulid(),
            'automation_run_id' => $run->id,
            'automation_id' => $automation->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'client_id' => $client?->id,
            'appointment_id' => $appointment?->id,
            'status' => 'processing',
            'trigger_reason' => $triggerReason,
            'context_json' => $this->targetContext($context),
        ]);

        try {
            $rendered = $this->renderMessage->execute($automation, $context);
            $payloadJson = array_merge($rendered['payload_json'], [
                'automation' => [
                    'type' => $automation->trigger_event,
                    'run_id' => $run->id,
                    'target_id' => $target->id,
                    'trigger_reason' => $triggerReason,
                    'target_reference' => [
                        'type' => $targetType,
                        'id' => $targetId,
                    ],
                ],
            ]);

            $message = $this->queueWhatsappMessage->execute([
                'client_id' => $client?->id,
                'appointment_id' => $appointment?->id,
                'automation_id' => $automation->id,
                'provider' => $rendered['provider'],
                'type' => $rendered['type'],
                'body_text' => $rendered['body_text'],
                'payload_json' => $payloadJson,
            ]);

            $target->forceFill([
                'message_id' => $message->id,
                'status' => 'queued',
                'skip_reason' => null,
                'failure_reason' => null,
                'cooldown_until' => now()->addHours(max(1, (int) $automation->cooldown_hours)),
            ])->save();

            return [
                'queued' => true,
                'failure_reason' => '',
            ];
        } catch (Throwable $throwable) {
            $target->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            return [
                'queued' => false,
                'failure_reason' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function targetContext(array $context): array
    {
        return array_filter([
            'tenant' => data_get($context, 'tenant'),
            'client' => data_get($context, 'client'),
            'appointment' => data_get($context, 'appointment'),
            'reactivation' => data_get($context, 'reactivation'),
        ], static fn (mixed $value): bool => $value !== null);
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

    private function cooldownActive(
        Automation $automation,
        string $targetType,
        string $targetId,
        CarbonImmutable $now,
    ): bool {
        $cooldownHours = max(1, (int) $automation->cooldown_hours);

        return AutomationRunTarget::query()
            ->where('automation_id', $automation->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', 'queued')
            ->where(function ($query) use ($now, $cooldownHours): void {
                $query
                    ->where('created_at', '>=', $now->subHours($cooldownHours))
                    ->orWhere('cooldown_until', '>', $now);
            })
            ->exists();
    }

    private function contactSkipReason(?Client $client, bool $requireMarketingOptIn): ?string
    {
        if ($client === null) {
            return 'missing_client';
        }

        if (! is_string($client->phone_e164) || trim($client->phone_e164) === '') {
            return 'missing_phone';
        }

        if (! $client->whatsapp_opt_in) {
            return 'whatsapp_opt_out';
        }

        if ($requireMarketingOptIn && ! $client->marketing_opt_in) {
            return 'marketing_opt_out';
        }

        return null;
    }

    private function reactivationBaseQuery(CarbonImmutable $now): \Illuminate\Database\Eloquent\Builder
    {
        return Client::query()
            ->select('clients.*')
            ->selectSub(
                Order::query()
                    ->selectRaw('MAX(closed_at)')
                    ->whereColumn('client_id', 'clients.id')
                    ->where('status', 'closed'),
                'last_closed_order_at',
            )
            ->selectSub(
                Appointment::query()
                    ->selectRaw('MAX(completed_at)')
                    ->whereColumn('client_id', 'clients.id')
                    ->where('status', 'completed'),
                'last_completed_appointment_at',
            )
            ->selectSub(
                Order::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('client_id', 'clients.id')
                    ->where('status', 'closed'),
                'closed_orders_count',
            )
            ->selectSub(
                Appointment::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('client_id', 'clients.id')
                    ->where('status', 'completed'),
                'completed_appointments_count',
            )
            ->selectSub(
                Appointment::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('client_id', 'clients.id')
                    ->whereNotIn('status', ['canceled', 'no_show', 'completed'])
                    ->where('starts_at', '>', $now),
                'future_appointments_count',
            )
            ->where(function ($query): void {
                $query
                    ->whereNotNull('last_visit_at')
                    ->orWhereExists(
                        Order::query()
                            ->selectRaw('1')
                            ->whereColumn('client_id', 'clients.id')
                            ->where('status', 'closed'),
                    )
                    ->orWhereExists(
                        Appointment::query()
                            ->selectRaw('1')
                            ->whereColumn('client_id', 'clients.id')
                            ->where('status', 'completed'),
                    );
            })
            ->orderBy('last_visit_at')
            ->orderBy('updated_at');
    }

    private function clientLastEngagementAt(Client $client): ?CarbonImmutable
    {
        $candidates = array_filter([
            $client->last_visit_at?->toIso8601String(),
            $client->getAttribute('last_closed_order_at'),
            $client->getAttribute('last_completed_appointment_at'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($candidates === []) {
            return null;
        }

        $latest = null;

        foreach ($candidates as $candidate) {
            $current = CarbonImmutable::parse((string) $candidate);

            if ($latest === null || $current->greaterThan($latest)) {
                $latest = $current;
            }
        }

        return $latest;
    }

    private function clientCompletedVisits(Client $client): int
    {
        return max(
            (int) ($client->visit_count ?? 0),
            (int) ($client->getAttribute('closed_orders_count') ?? 0),
            (int) ($client->getAttribute('completed_appointments_count') ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentAutomationContext(
        Automation $automation,
        Appointment $appointment,
        CarbonImmutable $now,
    ): array {
        $tenant = $this->tenantContext->current();
        $timezone = $tenant?->timezone ?: config('app.timezone', 'UTC');
        $startsAt = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone)
            : null;

        return [
            'tenant' => [
                'id' => $tenant?->id,
                'trade_name' => $tenant?->trade_name,
            ],
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'type' => $automation->trigger_event,
            ],
            'client' => $this->clientContext($appointment->client),
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'starts_at_local' => $startsAt?->format('d/m/Y H:i'),
                'date' => $startsAt?->format('d/m/Y'),
                'time' => $startsAt?->format('H:i'),
                'lead_time_minutes' => max(0, $now->diffInMinutes(CarbonImmutable::instance($appointment->starts_at), false)),
            ],
            'professional' => [
                'id' => $appointment->professional?->id,
                'full_name' => $appointment->professional?->full_name,
                'first_name' => $this->firstName($appointment->professional?->full_name),
            ],
            'service' => [
                'id' => $appointment->primaryService?->id,
                'name' => $appointment->primaryService?->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientReactivationContext(
        Automation $automation,
        Client $client,
        CarbonImmutable $lastEngagementAt,
        CarbonImmutable $now,
    ): array {
        $tenant = $this->tenantContext->current();
        $timezone = $tenant?->timezone ?: config('app.timezone', 'UTC');
        $localizedLastEngagement = $lastEngagementAt->setTimezone($timezone);

        return [
            'tenant' => [
                'id' => $tenant?->id,
                'trade_name' => $tenant?->trade_name,
            ],
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'type' => $automation->trigger_event,
            ],
            'client' => $this->clientContext($client),
            'reactivation' => [
                'inactive_days' => $lastEngagementAt->diffInDays($now),
                'last_visit_at' => $lastEngagementAt->toIso8601String(),
                'last_visit_at_local' => $localizedLastEngagement->format('d/m/Y H:i'),
                'completed_visits' => $this->clientCompletedVisits($client),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientContext(?Client $client): array
    {
        return [
            'id' => $client?->id,
            'full_name' => $client?->full_name,
            'first_name' => $this->firstName($client?->full_name),
            'phone_e164' => $client?->phone_e164,
        ];
    }

    private function firstName(?string $fullName): ?string
    {
        if (! is_string($fullName) || trim($fullName) === '') {
            return null;
        }

        return trim(explode(' ', trim($fullName))[0]);
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
