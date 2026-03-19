<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Product\WhatsappRelationshipViewFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildWhatsappRelationshipPanelDataAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
        private readonly WhatsappRelationshipViewFactory $viewFactory,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     filters:array<string, mixed>,
     *     cards:list<array<string, mixed>>,
     *     automations:array<string, array<string, mixed>>,
     *     appointments:list<array<string, mixed>>,
     *     reactivation_clients:list<array<string, mixed>>
     * }
     */
    public function execute(array $filters, bool $canTriggerManualMessages): array
    {
        $tenant = $this->tenantContext->current();
        abort_if(! $tenant instanceof Tenant, 404);

        $now = CarbonImmutable::now($tenant->timezone ?: config('app.timezone', 'UTC'));
        $selectedDate = $this->selectedDate($filters, $tenant);
        $appointmentAutomation = $this->automation(WhatsappAutomationType::AppointmentReminder);
        $reactivationAutomation = $this->automation(WhatsappAutomationType::InactiveClientReactivation);

        $appointments = $this->appointmentsForDate($selectedDate);
        $appointmentMessages = $this->latestMessagesByAppointment($appointments->pluck('id')->all());

        $appointmentItems = $appointments
            ->map(function (Appointment $appointment) use (
                $appointmentAutomation,
                $appointmentMessages,
                $tenant,
                $now,
                $canTriggerManualMessages,
            ): array {
                $candidate = $this->discoverCandidates->inspectAppointmentReminder(
                    automation: $appointmentAutomation,
                    appointment: $appointment,
                    now: $now,
                );

                return $this->viewFactory->appointmentItem(
                    appointment: $appointment,
                    candidate: $candidate,
                    automation: $appointmentAutomation,
                    latestMessage: $appointmentMessages->get($appointment->id),
                    now: $now,
                    tenant: $tenant,
                    canSendManualReminder: $canTriggerManualMessages,
                );
            })
            ->values()
            ->all();

        $reactivationCandidates = $this->discoverCandidates
            ->execute($reactivationAutomation, $now, 20)
            ->sortByDesc(fn ($candidate) => $candidate->isEligible())
            ->values();
        $reactivationMessages = $this->latestMessagesByClientForAutomation(
            clientIds: $reactivationCandidates->pluck('targetId')->filter()->all(),
            automationId: $reactivationAutomation->id,
        );

        $reactivationItems = $reactivationCandidates
            ->map(function ($candidate) use ($reactivationAutomation, $reactivationMessages, $tenant, $canTriggerManualMessages): array {
                return $this->viewFactory->reactivationClientItem(
                    candidate: $candidate,
                    automation: $reactivationAutomation,
                    latestMessage: $candidate->client !== null ? $reactivationMessages->get($candidate->client->id) : null,
                    tenant: $tenant,
                    canTriggerManual: $canTriggerManualMessages,
                );
            })
            ->values()
            ->all();

        $appointmentSummary = $this->discoverCandidates->summarize(
            automation: $appointmentAutomation,
            now: $now,
            limit: 100,
        );
        $reactivationSummary = $this->discoverCandidates->summarize(
            automation: $reactivationAutomation,
            now: $now,
            limit: 100,
        );

        return [
            'filters' => [
                'date' => $selectedDate->toDateString(),
            ],
            'cards' => $this->viewFactory->dashboardSummary(
                appointmentAutomation: $appointmentAutomation,
                appointmentSummary: $appointmentSummary,
                remindersSentToday: $this->remindersSentToday($selectedDate),
                reactivationAutomation: $reactivationAutomation,
                reactivationSummary: $reactivationSummary,
            ),
            'automations' => [
                'appointment_reminder' => [
                    'id' => $appointmentAutomation->id,
                    'status' => $appointmentAutomation->status,
                    'lead_time_minutes' => (int) data_get($appointmentAutomation->conditions_json, 'lead_time_minutes', 1440),
                ],
                'inactive_client_reactivation' => [
                    'id' => $reactivationAutomation->id,
                    'status' => $reactivationAutomation->status,
                    'inactivity_days' => (int) data_get($reactivationAutomation->conditions_json, 'inactivity_days', 45),
                ],
            ],
            'appointments' => $appointmentItems,
            'reactivation_clients' => $reactivationItems,
        ];
    }

    private function automation(WhatsappAutomationType $type): Automation
    {
        return $this->ensureDefaults->execute()
            ->firstWhere('trigger_event', $type->value)
            ?? Automation::query()
                ->where('channel', 'whatsapp')
                ->where('trigger_event', $type->value)
                ->firstOrFail();
    }

    private function selectedDate(array $filters, Tenant $tenant): CarbonImmutable
    {
        $value = is_string($filters['date'] ?? null) && $filters['date'] !== ''
            ? (string) $filters['date']
            : CarbonImmutable::now($tenant->timezone ?: config('app.timezone', 'UTC'))->toDateString();

        return CarbonImmutable::parse($value, $tenant->timezone ?: config('app.timezone', 'UTC'))->startOfDay();
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function appointmentsForDate(CarbonImmutable $selectedDate): Collection
    {
        return Appointment::query()
            ->with(['client', 'professional', 'primaryService'])
            ->whereBetween('starts_at', [$selectedDate, $selectedDate->copy()->addDays(2)->endOfDay()])
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->orderBy('starts_at')
            ->limit(18)
            ->get();
    }

    /**
     * @param  list<string>  $appointmentIds
     * @return Collection<string, Message>
     */
    private function latestMessagesByAppointment(array $appointmentIds): Collection
    {
        if ($appointmentIds === []) {
            return collect();
        }

        return Message::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->latest('created_at')
            ->get()
            ->unique('appointment_id')
            ->keyBy('appointment_id');
    }

    /**
     * @param  list<string>  $clientIds
     * @return Collection<string, Message>
     */
    private function latestMessagesByClientForAutomation(array $clientIds, string $automationId): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Message::query()
            ->whereIn('client_id', $clientIds)
            ->where('automation_id', $automationId)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->latest('created_at')
            ->get()
            ->unique('client_id')
            ->keyBy('client_id');
    }

    private function remindersSentToday(CarbonImmutable $selectedDate): int
    {
        return Appointment::query()
            ->whereBetween('reminder_sent_at', [$selectedDate, $selectedDate->copy()->endOfDay()])
            ->count();
    }
}
