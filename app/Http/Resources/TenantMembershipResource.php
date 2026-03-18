<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'is_primary' => $this->is_primary,
            'permissions_json' => $this->permissions_json,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->revoked_at !== null
                ? 'revoked'
                : ($this->accepted_at !== null ? 'active' : 'invited'),
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'status' => $this->user?->status,
                'locale' => $this->user?->locale,
            ]),
            'latest_invitation' => $this->whenLoaded('latestInvitation', fn (): ?array => $this->latestInvitation === null ? null : [
                'id' => $this->latestInvitation->id,
                'expires_at' => $this->latestInvitation->expires_at?->toIso8601String(),
                'accepted_at' => $this->latestInvitation->accepted_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
