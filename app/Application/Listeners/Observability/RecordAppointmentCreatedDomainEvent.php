<?php

namespace App\Application\Listeners\Observability;

use App\Application\Actions\Observability\RecordEventLogAction;
use App\Domain\Appointment\Events\AppointmentCreated;

class RecordAppointmentCreatedDomainEvent
{
    public function __construct(
        private readonly RecordEventLogAction $recordEventLog,
    ) {}

    public function handle(AppointmentCreated $event): void
    {
        $appointment = $event->appointment->loadMissing('client', 'professional', 'primaryService');

        $this->recordEventLog->execute(
            eventName: 'appointment.created',
            aggregateType: 'appointment',
            aggregateId: $appointment->id,
            triggerSource: 'domain_event',
            payload: [
                'appointment_id' => $appointment->id,
                'client_id' => $appointment->client_id,
                'professional_id' => $appointment->professional_id,
                'primary_service_id' => $appointment->primary_service_id,
                'source' => $appointment->source,
                'status' => $appointment->status,
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'ends_at' => $appointment->ends_at?->toIso8601String(),
                'duration_minutes' => $appointment->duration_minutes,
            ],
            context: [
                'client_name' => $appointment->client?->full_name,
                'professional_name' => $appointment->professional?->display_name,
                'service_name' => $appointment->primaryService?->name,
            ],
        );
    }
}
