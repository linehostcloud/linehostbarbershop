<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'professional_id' => $this->professional_id,
            'cash_register_session_id' => $this->cash_register_session_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'amount_cents' => $this->amount_cents,
            'balance_direction' => $this->balance_direction,
            'reconciled' => $this->reconciled,
            'metadata_json' => $this->metadata_json,
            'payment_provider' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->provider),
            'professional' => new ProfessionalResource($this->whenLoaded('professional')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
