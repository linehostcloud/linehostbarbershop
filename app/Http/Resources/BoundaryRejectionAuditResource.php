<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoundaryRejectionAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant_slug' => $this->tenant_slug,
            'actor_user_id' => $this->actor_user_id,
            'actor_email' => $this->actor_email,
            'direction' => $this->direction,
            'endpoint' => $this->endpoint,
            'route_name' => $this->route_name,
            'method' => $this->method,
            'host' => $this->host,
            'source_ip' => $this->source_ip,
            'provider' => $this->provider,
            'slot' => $this->slot,
            'code' => $this->code,
            'message' => $this->message,
            'http_status' => $this->http_status,
            'request_id' => $this->request_id,
            'correlation_id' => $this->correlation_id,
            'payload_json' => $this->payload_json,
            'headers_json' => $this->headers_json,
            'context_json' => $this->context_json,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
