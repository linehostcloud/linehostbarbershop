<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'birth_date' => $this->birth_date?->toDateString(),
            'retention_status' => $this->retention_status,
            'acquisition_channel' => $this->acquisition_channel,
            'marketing_opt_in' => $this->marketing_opt_in,
            'whatsapp_opt_in' => $this->whatsapp_opt_in,
            'visit_count' => $this->visit_count,
            'last_visit_at' => $this->last_visit_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
