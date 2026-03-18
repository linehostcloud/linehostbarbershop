<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Appointment\CreateAppointmentAction;
use App\Domain\Appointment\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AppointmentResource::collection(
            Appointment::query()
                ->with(['client', 'professional', 'primaryService'])
                ->orderBy('starts_at')
                ->paginate(15),
        );
    }

    public function store(StoreAppointmentRequest $request, CreateAppointmentAction $createAppointment): AppointmentResource
    {
        return new AppointmentResource(
            $createAppointment->execute($request->validated()),
        );
    }

    public function show(string $appointment): AppointmentResource
    {
        return new AppointmentResource(
            Appointment::query()
                ->with(['client', 'professional', 'primaryService'])
                ->findOrFail($appointment),
        );
    }
}
