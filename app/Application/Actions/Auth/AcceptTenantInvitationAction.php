<?php

namespace App\Application\Actions\Auth;

use App\Application\DTOs\IssuedTenantAccessToken;
use App\Domain\Auth\Models\TenantUserInvitation;
use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptTenantInvitationAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
        private readonly IssueTenantAccessTokenAction $issueTenantAccessToken,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{membership: TenantMembership, user: User, access_token: IssuedTenantAccessToken}
     */
    public function execute(array $payload, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($payload, $ipAddress, $userAgent): array {
                [$invitationId, $plainToken] = array_pad(explode('|', (string) $payload['token'], 2), 2, null);

                if (blank($invitationId) || blank($plainToken)) {
                    throw ValidationException::withMessages([
                        'token' => 'O token de convite informado e invalido.',
                    ]);
                }

                $invitation = TenantUserInvitation::query()
                    ->with(['tenant', 'membership.user'])
                    ->find($invitationId);

                if (
                    ! $invitation instanceof TenantUserInvitation
                    || ! hash_equals($invitation->token_hash, hash('sha256', $plainToken))
                    || $invitation->accepted_at !== null
                    || $invitation->isExpired()
                ) {
                    throw ValidationException::withMessages([
                        'token' => 'O token de convite informado e invalido ou expirou.',
                    ]);
                }

                $membership = $invitation->membership;

                if ($membership->revoked_at !== null) {
                    throw ValidationException::withMessages([
                        'token' => 'O convite informado nao esta mais disponivel.',
                    ]);
                }

                $user = $membership->user;
                $beforeMembership = $membership->only(['accepted_at', 'revoked_at']);
                $beforeUser = $user->only(['name', 'status', 'email_verified_at']);

                if (! $user->isActive()) {
                    if (blank($payload['password'])) {
                        throw ValidationException::withMessages([
                            'password' => 'Defina uma senha para concluir o aceite do convite.',
                        ]);
                    }

                    $user->fill([
                        'name' => $payload['name'] ?? $user->name,
                        'status' => 'active',
                        'email_verified_at' => $user->email_verified_at ?? now(),
                        'password' => $payload['password'],
                    ])->save();
                }

                $membership->forceFill([
                    'accepted_at' => now(),
                    'revoked_at' => null,
                ])->save();

                $invitation->forceFill([
                    'accepted_at' => now(),
                ])->save();

                $this->recordAuditLog->execute(
                    action: 'tenant_user.invitation_accepted',
                    tenant: $invitation->tenant,
                    actor: $user,
                    auditableType: TenantMembership::class,
                    auditableId: $membership->id,
                    before: [
                        'membership' => $beforeMembership,
                        'user' => $beforeUser,
                    ],
                    after: [
                        'membership' => $membership->fresh()->only(['accepted_at', 'revoked_at']),
                        'user' => $user->fresh()->only(['name', 'status', 'email_verified_at']),
                    ],
                    metadata: [
                        'invitation_id' => $invitation->id,
                    ],
                );

                $issuedToken = $this->issueTenantAccessToken->execute($user, $invitation->tenant, [
                    'name' => $payload['device_name'] ?? 'invitation-accept',
                    'abilities' => ['*'],
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ]);

                return [
                    'membership' => $membership->fresh(['user', 'latestInvitation']),
                    'user' => $user->fresh(),
                    'access_token' => $issuedToken,
                ];
            });
    }
}
