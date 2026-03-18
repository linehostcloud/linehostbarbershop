<?php

namespace App\Application\Actions\Auth;

use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTenantMembershipAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(TenantMembership $membership, User $actor, array $payload): TenantMembership
    {
        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($membership, $actor, $payload): TenantMembership {
                $before = $membership->only([
                    'role',
                    'permissions_json',
                    'accepted_at',
                    'revoked_at',
                ]);

                $newRole = $payload['role'] ?? $membership->role;
                $newRevokedAt = array_key_exists('revoked', $payload)
                    ? ($payload['revoked'] ? now() : null)
                    : $membership->revoked_at;

                if (
                    $membership->role === 'owner'
                    && ($newRole !== 'owner' || $newRevokedAt !== null)
                    && $membership->tenant->memberships()
                        ->where('role', 'owner')
                        ->whereNull('revoked_at')
                        ->whereKeyNot($membership->id)
                        ->count() === 0
                ) {
                    throw ValidationException::withMessages([
                        'role' => 'O ultimo owner ativo do tenant nao pode perder esse papel.',
                    ]);
                }

                $membership->fill([
                    'role' => $newRole,
                    'permissions_json' => $payload['permissions_json'] ?? $membership->permissions_json,
                    'revoked_at' => $newRevokedAt,
                ])->save();

                $membership->load(['tenant', 'user', 'latestInvitation']);

                $this->recordAuditLog->execute(
                    action: 'tenant_user.membership_updated',
                    tenant: $membership->tenant,
                    actor: $actor,
                    auditableType: TenantMembership::class,
                    auditableId: $membership->id,
                    before: $before,
                    after: $membership->only([
                        'role',
                        'permissions_json',
                        'accepted_at',
                        'revoked_at',
                    ]),
                    metadata: [
                        'user_id' => $membership->user_id,
                    ],
                );

                return $membership;
            });
    }
}
