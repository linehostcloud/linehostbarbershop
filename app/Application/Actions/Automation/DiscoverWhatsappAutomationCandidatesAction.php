<?php

namespace App\Application\Actions\Automation;

use App\Application\DTOs\WhatsappAutomationCandidate;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Client\Models\Client;
use App\Domain\Order\Models\Order;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DiscoverWhatsappAutomationCandidatesAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return Collection<int, WhatsappAutomationCandidate>
     */
    public function execute(Automation $automation, CarbonImmutable $now, int $limit): Collection
    {
        return match (WhatsappAutomationType::from((string) $automation->trigger_event)) {
            WhatsappAutomationType::AppointmentReminder => $this->appointmentReminderCandidates($automation, $now, $limit),
            WhatsappAutomationType::InactiveClientReactivation => $this->inactiveClientReactivationCandidates($automation, $now, $limit),
        };
    }

    /**
     * @return array{eligible_total:int,candidates_found:int,skipped_total:int,skip_reasons:array<string,int>}
     */
    public function summarize(Automation $automation, CarbonImmutable $now, int $limit): array
    {
        $candidates = $this->execute($automation, $now, $limit);
        $skipReasons = [];

        foreach ($candidates->where('status', 'skipped') as $candidate) {
            if ($candidate->skipReason === null) {
                continue;
            }

            $skipReasons[$candidate->skipReason] = (int) (($skipReasons[$candidate->skipReason] ?? 0) + 1);
        }

        return [
            'eligible_total' => $candidates->where('status', 'eligible')->count(),
            'candidates_found' => $candidates->count(),
            'skipped_total' => $candidates->where('status', 'skipped')->count(),
            'skip_reasons' => $skipReasons,
        ];
    }

    public function inspectAppointmentReminder(
        Automation $automation,
        Appointment $appointment,
        CarbonImmutable $now,
        bool $respectWindow = true,
        bool $respectAlreadySent = true,
    ): WhatsappAutomationCandidate {
        $context = $this->appointmentAutomationContext($automation, $appointment, $now);
        $excludedStatuses = array_values(array_filter(
            (array) data_get($automation->conditions_json, 'excluded_statuses', ['canceled', 'no_show', 'completed']),
            'is_string',
        ));

        if ($respectWindow && ! $this->appointmentInsideReminderWindow($automation, $appointment, $now)) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                skipReason: 'outside_reminder_window',
                client: $appointment->client,
                appointment: $appointment,
                context: $context,
            );
        }

        if (in_array((string) $appointment->status, $excludedStatuses, true) || $appointment->canceled_at !== null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                skipReason: 'appointment_not_eligible',
                client: $appointment->client,
                appointment: $appointment,
                context: $context,
            );
        }

        if ($respectAlreadySent && $appointment->reminder_sent_at !== null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                skipReason: 'reminder_already_sent',
                client: $appointment->client,
                appointment: $appointment,
                context: $context,
            );
        }

        if (($skipReason = $this->contactSkipReason($appointment->client, false)) !== null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                skipReason: $skipReason,
                client: $appointment->client,
                appointment: $appointment,
                context: $context,
            );
        }

        if ($this->cooldownActive($automation, 'appointment', (string) $appointment->id, $now)) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'appointment',
                targetId: (string) $appointment->id,
                triggerReason: 'appointment_due_soon',
                skipReason: 'cooldown_active',
                client: $appointment->client,
                appointment: $appointment,
                context: $context,
            );
        }

        return new WhatsappAutomationCandidate(
            status: 'eligible',
            targetType: 'appointment',
            targetId: (string) $appointment->id,
            triggerReason: 'appointment_due_soon',
            skipReason: null,
            client: $appointment->client,
            appointment: $appointment,
            context: $context,
        );
    }

    public function inspectInactiveClientReactivation(
        Automation $automation,
        Client $client,
        CarbonImmutable $now,
    ): WhatsappAutomationCandidate {
        $inactivityDays = (int) data_get($automation->conditions_json, 'inactivity_days', 45);
        $minimumCompletedVisits = max(1, (int) data_get($automation->conditions_json, 'minimum_completed_visits', 1));
        $requireMarketingOptIn = (bool) data_get($automation->conditions_json, 'require_marketing_opt_in', true);
        $excludeWithFutureAppointments = (bool) data_get($automation->conditions_json, 'exclude_with_future_appointments', true);
        $lastEngagementAt = $this->clientLastEngagementAt($client);
        $contextFallback = $lastEngagementAt ?? $now;

        if ($lastEngagementAt === null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'no_visit_history',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $contextFallback, $now),
            );
        }

        if (($skipReason = $this->contactSkipReason($client, $requireMarketingOptIn)) !== null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: $skipReason,
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        if ($this->clientCompletedVisits($client) < $minimumCompletedVisits) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'insufficient_history',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        if ($excludeWithFutureAppointments && (int) ($client->future_appointments_count ?? 0) > 0) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'future_appointment_exists',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        if ($lastEngagementAt->diffInDays($now) < $inactivityDays) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'not_inactive_enough',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        if (($snoozedUntil = $this->clientReactivationSnoozedUntil($client, $now)) !== null) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'reactivation_snoozed',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        if ($this->cooldownActive($automation, 'client', (string) $client->id, $now)) {
            return new WhatsappAutomationCandidate(
                status: 'skipped',
                targetType: 'client',
                targetId: (string) $client->id,
                triggerReason: 'inactive_for_reactivation',
                skipReason: 'cooldown_active',
                client: $client,
                appointment: null,
                context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
            );
        }

        return new WhatsappAutomationCandidate(
            status: 'eligible',
            targetType: 'client',
            targetId: (string) $client->id,
            triggerReason: 'inactive_for_reactivation',
            skipReason: null,
            client: $client,
            appointment: null,
            context: $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now),
        );
    }

    /**
     * @return Collection<int, WhatsappAutomationCandidate>
     */
    private function appointmentReminderCandidates(
        Automation $automation,
        CarbonImmutable $now,
        int $limit,
    ): Collection {
        [$windowStart, $windowEnd] = $this->appointmentReminderWindow($automation, $now);
        $overFetch = max($limit * 5, 25);

        $appointments = Appointment::query()
            ->with(['client', 'professional', 'primaryService'])
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->orderBy('starts_at')
            ->limit($overFetch)
            ->get();

        $candidates = collect();

        foreach ($appointments as $appointment) {
            if ($candidates->count() >= $limit) {
                break;
            }

            $candidates->push($this->inspectAppointmentReminder($automation, $appointment, $now));
        }

        return $candidates;
    }

    /**
     * @return Collection<int, WhatsappAutomationCandidate>
     */
    private function inactiveClientReactivationCandidates(
        Automation $automation,
        CarbonImmutable $now,
        int $limit,
    ): Collection {
        $overFetch = max($limit * 5, 50);

        $clients = $this->reactivationBaseQuery($now)
            ->limit($overFetch)
            ->get();

        $candidates = collect();

        foreach ($clients as $client) {
            if ($candidates->count() >= $limit) {
                break;
            }

            $candidates->push($this->inspectInactiveClientReactivation($automation, $client, $now));
        }

        return $candidates;
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    public function appointmentReminderWindow(Automation $automation, CarbonImmutable $now): array
    {
        $leadTimeMinutes = (int) data_get($automation->conditions_json, 'lead_time_minutes', 1440);
        $toleranceMinutes = (int) data_get($automation->conditions_json, 'selection_tolerance_minutes', config('communication.whatsapp.automations.selection_tolerance_minutes', 10));

        return [
            $now->addMinutes($leadTimeMinutes)->subMinutes($toleranceMinutes),
            $now->addMinutes($leadTimeMinutes)->addMinutes($toleranceMinutes),
        ];
    }

    public function appointmentContext(
        Automation $automation,
        Appointment $appointment,
        CarbonImmutable $now,
    ): array {
        return $this->appointmentAutomationContext($automation, $appointment, $now);
    }

    public function clientReactivationContextFromClient(
        Automation $automation,
        Client $client,
        CarbonImmutable $now,
    ): array {
        $lastEngagementAt = $this->clientLastEngagementAt($client) ?? $now;

        return $this->clientReactivationContext($automation, $client, $lastEngagementAt, $now);
    }

    public function loadClientForReactivation(Client $client, CarbonImmutable $now): Client
    {
        return $this->reactivationBaseQuery($now)
            ->whereKey($client->getKey())
            ->firstOrFail();
    }

    public function appointmentInsideReminderWindow(
        Automation $automation,
        Appointment $appointment,
        CarbonImmutable $now,
    ): bool {
        if ($appointment->starts_at === null) {
            return false;
        }

        [$windowStart, $windowEnd] = $this->appointmentReminderWindow($automation, $now);
        $startsAt = CarbonImmutable::instance($appointment->starts_at);

        return $startsAt->greaterThanOrEqualTo($windowStart)
            && $startsAt->lessThanOrEqualTo($windowEnd);
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
            ->where(function (Builder $query) use ($now, $cooldownHours): void {
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

    private function reactivationBaseQuery(CarbonImmutable $now): Builder
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
            ->where(function (Builder $query): void {
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
                'snoozed_until' => $this->clientReactivationSnoozedUntil($client, $now)?->toIso8601String(),
                'snoozed_until_local' => $this->clientReactivationSnoozedUntil($client, $now)?->setTimezone($timezone)->format('d/m/Y H:i'),
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

    private function clientReactivationSnoozedUntil(Client $client, CarbonImmutable $now): ?CarbonImmutable
    {
        $value = $client->whatsapp_reactivation_snoozed_until;

        if ($value === null) {
            return null;
        }

        $snoozedUntil = $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::instance($value);

        return $snoozedUntil->greaterThan($now) ? $snoozedUntil : null;
    }
}
