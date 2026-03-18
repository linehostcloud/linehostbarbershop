<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'client_id' => $this->client_id,
            'provider' => $this->provider,
            'gateway' => $this->gateway,
            'external_reference' => $this->external_reference,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'installment_count' => $this->installment_count,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'metadata_json' => $this->metadata_json,
            'client' => new ClientResource($this->whenLoaded('client')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
