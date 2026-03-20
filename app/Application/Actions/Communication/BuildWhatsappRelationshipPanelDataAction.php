<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Appointment\DetermineManualAppointmentConfirmationEligibilityAction;
use App\Application\Actions\Automation\BuildWhatsappAutomationDefaultAttributesAction;
use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Support\WhatsappRelationshipMetricsPeriod;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Product\WhatsappRelationshipViewFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildWhatsappRelationshipPanelDataAction
{
    public function __construct(
        private readonly BuildWhatsappRelationshipMetricsAction $buildMetrics,
        private readonly BuildWhatsappAutomationDefaultAttributesAction $buildAutomationDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
        private readonly DetermineManualAppointmentConfirmationEligibilityAction $determineConfirmationEligibility,
        private readonly WhatsappRelationshipViewFactory $viewFactory,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{
     *     appointments:array{read:bool,write:bool},
     *     clients:array{read:bool,write:bool}
     * }  $visibility
     * @return array{
     *     filters:array<string, mixed>,
     *     sections:array{appointments:bool,reactivation:bool},
     *     metrics:array{
     *         period:array<string, mixed>,
     *         cards:list<array<string, mixed>>,
     *         has_inferred_cards:bool
     *     },
     *     automations:array<string, array<string, mixed>>,
     *     appointments:list<array<string, mixed>>,
     *     reactivation_clients:list<array<string, mixed>>
     * }
     */
    public function execute(array $filters, array $visibility): array
    {
        $tenant = $this->tenantContext->current();
        abort_if(! $tenant instanceof Tenant, 404);

        $now = CarbonImmutable::now($tenant->timezone ?: config('app.timezone', 'UTC'));
        $selectedDate = $this->selectedDate($filters, $tenant);
        $appointmentAutomation = $this->automation(WhatsappAutomationType::AppointmentReminder);
        $reactivationAutomation = $this->automation(WhatsappAutomationType::InactiveClientReactivation);
        $appointmentItems = [];
        $reactivationItems = [];

        if (($visibility['appointments']['read'] ?? false) === true) {
            $appointments = $this->appointmentsForDate($selectedDate);
            $appointmentMessages = $this->latestAppointmentMessages(
                appointmentIds: $appointments->pluck('id')->all(),
                automationId: $appointmentAutomation->id,
            );

            $appointmentItems = $appointments
                ->map(function (Appointment $appointment) use (
                    $appointmentAutomation,
                    $appointmentMessages,
                    $tenant,
                    $now,
                    $visibility,
                ): array {
                    $automaticCandidate = $this->discoverCandidates->inspectAppointmentReminder(
                        automation: $appointmentAutomation,
                        appointment: $appointment,
                        now: $now,
                    );
                    $manualCandidate = $this->discoverCandidates->inspectAppointmentReminder(
                        automation: $appointmentAutomation,
                        appointment: $appointment,
                        now: $now,
                        respectWindow: false,
                        respectAlreadySent: false,
                    );
                    $latestConfirmationMessage = $appointmentMessages['confirmation']->get($appointment->id);
                    $confirmationState = $this->determineConfirmationEligibility->execute(
                        appointment: $appointment,
                        latestConfirmationMessage: $latestConfirmationMessage,
                        now: $now,
                        automation: $appointmentAutomation,
                    );

                    return $this->viewFactory->appointmentItem(
                        appointment: $appointment,
                        automaticCandidate: $automaticCandidate,
                        manualCandidate: $manualCandidate,
                        automation: $appointmentAutomation,
                        latestReminderMessage: $appointmentMessages['reminder']->get($appointment->id),
                        latestConfirmationMessage: $latestConfirmationMessage,
                        confirmationState: $confirmationState,
                        now: $now,
                        tenant: $tenant,
                        canSendManualReminder: (bool) ($visibility['appointments']['write'] ?? false),
                        canSendManualConfirmation: (bool) ($visibility['appointments']['write'] ?? false),
                    );
                })
                ->values()
                ->all();
        }

        if (($visibility['clients']['read'] ?? false) === true) {
            $reactivationCandidates = $this->discoverCandidates
                ->execute($reactivationAutomation, $now, 20)
                ->sortByDesc(fn ($candidate) => $candidate->isEligible())
                ->values();
            $reactivationMessages = $this->latestMessagesByClientForAutomation(
                clientIds: $reactivationCandidates->pluck('targetId')->filter()->all(),
                automationId: $reactivationAutomation->id,
            );

            $reactivationItems = $reactivationCandidates
                ->map(function ($candidate) use ($reactivationAutomation, $reactivationMessages, $tenant, $visibility): array {
                    return $this->viewFactory->reactivationClientItem(
                        candidate: $candidate,
                        automation: $reactivationAutomation,
                        latestMessage: $candidate->client !== null ? $reactivationMessages->get($candidate->client->id) : null,
                        tenant: $tenant,
                        canTriggerManual: (bool) ($visibility['clients']['write'] ?? false),
                    );
                })
                ->values()
                ->all();
        }

        return [
            'filters' => [
                'date' => $selectedDate->toDateString(),
                'date_label' => $selectedDate->format('d/m/Y'),
                'period' => WhatsappRelationshipMetricsPeriod::fromInput($filters['period'] ?? null)->value,
            ],
            'sections' => [
                'appointments' => (bool) ($visibility['appointments']['read'] ?? false),
                'reactivation' => (bool) ($visibility['clients']['read'] ?? false),
            ],
            'metrics' => $this->buildMetrics->execute(
                tenant: $tenant,
                appointmentAutomation: $appointmentAutomation,
                reactivationAutomation: $reactivationAutomation,
                filters: [
                    'period' => WhatsappRelationshipMetricsPeriod::fromInput($filters['period'] ?? null)->value,
                ],
                visibility: $visibility,
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
        return Automation::query()
            ->where('channel', 'whatsapp')
            ->where('trigger_event', $type->value)
            ->first()
            ?? new Automation($this->buildAutomationDefaults->execute($type));
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
     * @return array{reminder:Collection<string, Message>,confirmation:Collection<string, Message>}
     */
    private function latestAppointmentMessages(array $appointmentIds, ?string $automationId): array
    {
        if ($appointmentIds === [] || ! is_string($automationId) || $automationId === '') {
            return [
                'reminder' => collect(),
                'confirmation' => collect(),
            ];
        }

        $reminder = collect();
        $confirmation = collect();

        Message::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->where('automation_id', $automationId)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->latest('created_at')
            ->get()
            ->each(function (Message $message) use (&$reminder, &$confirmation): void {
                $appointmentId = (string) $message->appointment_id;

                if ($appointmentId === '') {
                    return;
                }

                if ($this->isConfirmationMessage($message)) {
                    if (! $confirmation->has($appointmentId)) {
                        $confirmation->put($appointmentId, $message);
                    }

                    return;
                }

                if (! $reminder->has($appointmentId)) {
                    $reminder->put($appointmentId, $message);
                }
            });

        return [
            'reminder' => $reminder,
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @param  list<string>  $clientIds
     * @return Collection<string, Message>
     */
    private function latestMessagesByClientForAutomation(array $clientIds, ?string $automationId): Collection
    {
        if ($clientIds === [] || ! is_string($automationId) || $automationId === '') {
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

    private function isConfirmationMessage(Message $message): bool
    {
        return data_get($message->payload_json, 'product.manual_action') === 'appointment_confirmation'
            || data_get($message->payload_json, 'automation.trigger_reason') === 'manual_appointment_confirmation';
    }
}
