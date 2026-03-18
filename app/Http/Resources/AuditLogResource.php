<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'actor_user_id' => $this->actor_user_id,
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'before_json' => $this->before_json,
            'after_json' => $this->after_json,
            'metadata_json' => $this->metadata_json,
            'actor' => $this->whenLoaded('actor', fn (): array => [
                'id' => $this->actor?->id,
                'name' => $this->actor?->name,
                'email' => $this->actor?->email,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
