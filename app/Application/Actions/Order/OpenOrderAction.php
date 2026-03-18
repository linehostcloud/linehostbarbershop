<?php

namespace App\Application\Actions\Order;

use App\Domain\Appointment\Models\Appointment;
use App\Domain\Client\Models\Client;
use App\Domain\Order\Models\Order;
use App\Domain\Professional\Models\Professional;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenOrderAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): Order
    {
        $appointment = isset($payload['appointment_id'])
            ? Appointment::query()->findOrFail($payload['appointment_id'])
            : null;

        if ($appointment !== null && Order::query()->where('appointment_id', $appointment->id)->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => 'Ja existe uma comanda vinculada a este agendamento.',
            ]);
        }

        $client = isset($payload['client_id'])
            ? Client::query()->findOrFail($payload['client_id'])
            : null;

        if ($client === null && $appointment !== null) {
            $client = $appointment->client;
        }

        $professional = isset($payload['primary_professional_id'])
            ? Professional::query()->findOrFail($payload['primary_professional_id'])
            : null;

        if ($professional === null && $appointment !== null) {
            $professional = $appointment->professional;
        }

        return DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($payload, $appointment, $client, $professional) {
                return Order::query()->create([
                    'client_id' => $client?->id,
                    'appointment_id' => $appointment?->id,
                    'primary_professional_id' => $professional?->id,
                    'opened_by_user_id' => $payload['opened_by_user_id'] ?? null,
                    'closed_by_user_id' => null,
                    'origin' => $payload['origin'] ?? ($appointment ? 'appointment' : 'walk_in'),
                    'status' => 'open',
                    'subtotal_cents' => 0,
                    'discount_cents' => 0,
                    'fee_cents' => 0,
                    'total_cents' => 0,
                    'amount_paid_cents' => 0,
                    'opened_at' => isset($payload['opened_at']) ? Carbon::parse($payload['opened_at']) : now(),
                    'closed_at' => null,
                    'notes' => $payload['notes'] ?? null,
                ]);
            })
            ->load(['client', 'appointment', 'primaryProfessional', 'items']);
    }
}
