<?php

namespace App\Application\Actions\Auth;

use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetTenantUserPasswordAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{membership: TenantMembership, temporary_password: string}
     */
    public function execute(TenantMembership $membership, User $actor, array $payload): array
    {
        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($membership, $actor, $payload): array {
                $temporaryPassword = $payload['password'] ?? Str::password(16);
                $user = $membership->user;
                $before = $user->only(['status']);

                $user->fill([
                    'status' => 'active',
                    'password' => $temporaryPassword,
                ])->save();

                if (($payload['invalidate_tokens'] ?? true) === true) {
                    $user->accessTokens()
                        ->where('tenant_id', $membership->tenant_id)
                        ->delete();
                }

                $this->recordAuditLog->execute(
                    action: 'tenant_user.password_reset',
                    tenant: $membership->tenant,
                    actor: $actor,
                    auditableType: User::class,
                    auditableId: $user->id,
                    before: $before,
                    after: $user->only(['status']),
                    metadata: [
                        'membership_id' => $membership->id,
                        'tokens_invalidated' => (bool) ($payload['invalidate_tokens'] ?? true),
                    ],
                );

                return [
                    'membership' => $membership->fresh(['user', 'latestInvitation']),
                    'temporary_password' => $temporaryPassword,
                ];
            });
    }
}
