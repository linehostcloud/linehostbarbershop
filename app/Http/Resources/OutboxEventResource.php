<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutboxEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_log_id' => $this->event_log_id,
            'message_id' => $this->message_id,
            'event_name' => $this->event_name,
            'topic' => $this->topic,
            'aggregate_type' => $this->aggregate_type,
            'aggregate_id' => $this->aggregate_id,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'retry_backoff_seconds' => $this->retry_backoff_seconds,
            'payload_json' => $this->payload_json,
            'context_json' => $this->context_json,
            'available_at' => $this->available_at?->toIso8601String(),
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'reclaim_count' => $this->reclaim_count,
            'last_reclaimed_at' => $this->last_reclaimed_at?->toIso8601String(),
            'last_reclaim_reason' => $this->last_reclaim_reason,
            'event_log' => new EventLogResource($this->whenLoaded('eventLog')),
            'message' => new MessageResource($this->whenLoaded('message')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
