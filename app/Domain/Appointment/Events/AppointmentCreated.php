<?php

namespace App\Domain\Appointment\Events;

use App\Domain\Appointment\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Appointment $appointment)
    {
    }
}
