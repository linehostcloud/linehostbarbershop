<?php

namespace App\Application\Actions\Auth;

use App\Domain\Auth\Models\TenantUserInvitation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteTenantUserAction
{
    public function __construct(
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{membership: TenantMembership, invitation: TenantUserInvitation, plain_text_token: string, user_created: bool}
     */
    public function execute(Tenant $tenant, User $actor, array $payload): array
    {
        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($tenant, $actor, $payload): array {
                $email = strtolower(trim((string) $payload['email']));
                $userCreated = false;
                $user = User::query()
                    ->where('email', $email)
                    ->first();

                if ($user === null) {
                    $user = User::query()->create([
                        'name' => $payload['name'] ?? Str::before($email, '@'),
                        'email' => $email,
                        'phone_e164' => null,
                        'locale' => 'pt_BR',
                        'status' => 'invited',
                        'password' => Hash::make(Str::password(32)),
                    ]);

                    $userCreated = true;
                }

                $membership = TenantMembership::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($membership?->isActive()) {
                    throw ValidationException::withMessages([
                        'email' => 'O usuario informado ja possui acesso ativo a este tenant.',
                    ]);
                }

                $before = $membership?->only([
                    'role',
                    'permissions_json',
                    'invited_at',
                    'accepted_at',
                    'revoked_at',
                ]);

                if ($membership === null) {
                    $membership = TenantMembership::query()->create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'role' => $payload['role'],
                        'is_primary' => false,
                        'permissions_json' => $payload['permissions_json'] ?? null,
                        'invited_at' => now(),
                        'accepted_at' => null,
                        'revoked_at' => null,
                    ]);
                } else {
                    $membership->fill([
                        'role' => $payload['role'],
                        'permissions_json' => $payload['permissions_json'] ?? $membership->permissions_json,
                        'invited_at' => now(),
                        'accepted_at' => null,
                        'revoked_at' => null,
                    ])->save();
                }

                TenantUserInvitation::query()
                    ->where('tenant_membership_id', $membership->id)
                    ->whereNull('accepted_at')
                    ->delete();

                $plainToken = Str::random(64);
                $invitation = TenantUserInvitation::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'tenant_membership_id' => $membership->id,
                    'invited_by_user_id' => $actor->id,
                    'token_hash' => hash('sha256', $plainToken),
                    'expires_at' => now()->addMinutes((int) ($payload['expires_in_minutes'] ?? 4320)),
                    'metadata_json' => [
                        'email' => $email,
                    ],
                ]);

                $membership->load(['user', 'latestInvitation']);

                $this->recordAuditLog->execute(
                    action: 'tenant_user.invited',
                    tenant: $tenant,
                    actor: $actor,
                    auditableType: TenantMembership::class,
                    auditableId: $membership->id,
                    before: $before,
                    after: $membership->only([
                        'role',
                        'permissions_json',
                        'invited_at',
                        'accepted_at',
                        'revoked_at',
                    ]),
                    metadata: [
                        'invitation_id' => $invitation->id,
                        'user_id' => $user->id,
                        'user_created' => $userCreated,
                    ],
                );

                return [
                    'membership' => $membership,
                    'invitation' => $invitation,
                    'plain_text_token' => sprintf('%s|%s', $invitation->id, $plainToken),
                    'user_created' => $userCreated,
                ];
            });
    }
}
