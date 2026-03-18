<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'service_id' => $this->service_id,
            'professional_id' => $this->professional_id,
            'type' => $this->type,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'total_price_cents' => $this->total_price_cents,
            'commission_percent' => $this->commission_percent,
            'service' => new ServiceResource($this->whenLoaded('service')),
            'professional' => new ProfessionalResource($this->whenLoaded('professional')),
        ];
    }
}
