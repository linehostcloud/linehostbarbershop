<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'event_log_id' => $this->event_log_id,
            'outbox_event_id' => $this->outbox_event_id,
            'channel' => $this->channel,
            'provider' => $this->provider,
            'operation' => $this->operation,
            'direction' => $this->direction,
            'status' => $this->status,
            'external_reference' => $this->external_reference,
            'provider_message_id' => $this->provider_message_id,
            'provider_status' => $this->provider_status,
            'provider_error_code' => $this->provider_error_code,
            'provider_request_id' => $this->provider_request_id,
            'http_status' => $this->http_status,
            'latency_ms' => $this->latency_ms,
            'retryable' => $this->retryable,
            'normalized_status' => $this->normalized_status,
            'normalized_error_code' => $this->normalized_error_code,
            'idempotency_key' => $this->idempotency_key,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'request_payload_json' => $this->request_payload_json,
            'response_payload_json' => $this->response_payload_json,
            'sanitized_payload_json' => $this->sanitized_payload_json,
            'message' => new MessageResource($this->whenLoaded('message')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
