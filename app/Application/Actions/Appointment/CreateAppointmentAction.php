<?php

namespace App\Application\Actions\Appointment;

use App\Domain\Appointment\Events\AppointmentCreated;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Client\Models\Client;
use App\Domain\Professional\Models\Professional;
use App\Domain\Service\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAppointmentAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): Appointment
    {
        $client = Client::query()->findOrFail($payload['client_id']);
        $professional = Professional::query()->findOrFail($payload['professional_id']);
        $service = isset($payload['primary_service_id'])
            ? Service::query()->findOrFail($payload['primary_service_id'])
            : null;

        $startsAt = Carbon::parse($payload['starts_at']);
        $duration = (int) ($payload['duration_minutes'] ?? $service?->duration_minutes ?? 0);

        if ($duration <= 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'A duracao do agendamento precisa ser maior que zero.',
            ]);
        }

        $endsAt = isset($payload['ends_at'])
            ? Carbon::parse($payload['ends_at'])
            : $startsAt->copy()->addMinutes($duration);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'O horario final precisa ser posterior ao horario inicial.',
            ]);
        }

        $hasConflict = Appointment::query()
            ->where('professional_id', $professional->id)
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->where(function ($query) use ($startsAt, $endsAt): void {
                $query
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt);
            })
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'professional_id' => 'O profissional ja possui um agendamento nesse intervalo.',
            ]);
        }

        $appointment = DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($payload, $client, $professional, $service, $startsAt, $endsAt, $duration) {
                return Appointment::query()->create([
                    'client_id' => $client->id,
                    'professional_id' => $professional->id,
                    'primary_service_id' => $service?->id,
                    'subscription_id' => $payload['subscription_id'] ?? null,
                    'booked_by_user_id' => $payload['booked_by_user_id'] ?? null,
                    'source' => $payload['source'] ?? 'dashboard',
                    'status' => $payload['status'] ?? 'pending',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'duration_minutes' => $duration,
                    'confirmation_status' => $payload['confirmation_status'] ?? 'not_sent',
                    'notes' => $payload['notes'] ?? null,
                ]);
            });

        event(new AppointmentCreated($appointment));

        return $appointment->load(['client', 'professional', 'primaryService']);
    }
}
