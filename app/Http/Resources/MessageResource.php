<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'appointment_id' => $this->appointment_id,
            'automation_id' => $this->automation_id,
            'direction' => $this->direction,
            'channel' => $this->channel,
            'provider' => $this->provider,
            'external_message_id' => $this->external_message_id,
            'thread_key' => $this->thread_key,
            'type' => $this->type,
            'status' => $this->status,
            'body_text' => $this->body_text,
            'payload_json' => $this->payload_json,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'client' => new ClientResource($this->whenLoaded('client')),
            'integration_attempts' => IntegrationAttemptResource::collection($this->whenLoaded('integrationAttempts')),
            'outbox_events' => OutboxEventResource::collection($this->whenLoaded('outboxEvents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
