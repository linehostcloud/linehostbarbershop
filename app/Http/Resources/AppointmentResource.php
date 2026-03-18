<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'professional_id' => $this->professional_id,
            'primary_service_id' => $this->primary_service_id,
            'source' => $this->source,
            'status' => $this->status,
            'confirmation_status' => $this->confirmation_status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'notes' => $this->notes,
            'client' => new ClientResource($this->whenLoaded('client')),
            'professional' => new ProfessionalResource($this->whenLoaded('professional')),
            'primary_service' => new ServiceResource($this->whenLoaded('primaryService')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
