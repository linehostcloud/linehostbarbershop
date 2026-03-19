<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'message_id' => $this->message_id,
            'aggregate_type' => $this->aggregate_type,
            'aggregate_id' => $this->aggregate_id,
            'event_name' => $this->event_name,
            'trigger_source' => $this->trigger_source,
            'status' => $this->status,
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'causation_id' => $this->causation_id,
            'payload_json' => $this->payload_json,
            'context_json' => $this->context_json,
            'result_json' => $this->result_json,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'message' => new MessageResource($this->whenLoaded('message')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
