<?php

namespace App\Application\Actions\Auth;

use App\Application\DTOs\AuthenticatedTenantSessionResult;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticateTenantCredentialsAction
{
    public function __construct(
        private readonly IssueTenantAccessTokenAction $issueTenantAccessToken,
        private readonly TenantPermissionMatrix $tenantPermissionMatrix,
    ) {
    }

    public function execute(
        Tenant $tenant,
        string $email,
        string $password,
        ?string $deviceName,
        ?string $ipAddress,
        ?string $userAgent,
    ): AuthenticatedTenantSessionResult {
        $user = User::query()
            ->where('email', strtolower(trim($email)))
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'As credenciais informadas sao invalidas.',
            ]);
        }

        if (! $user->isActive()) {
            abort(403, 'O usuario informado esta bloqueado ou inativo.');
        }

        $membership = $user->memberships()
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $membership instanceof TenantMembership || ! $membership->isActive()) {
            abort(403, 'O usuario informado nao possui acesso ativo a este tenant.');
        }

        $issuedToken = $this->issueTenantAccessToken->execute($user, $tenant, [
            'name' => $deviceName ?: 'painel-web',
            'abilities' => ['*'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return new AuthenticatedTenantSessionResult(
            plainTextToken: $issuedToken->plainTextToken,
            accessToken: $issuedToken->accessToken,
            user: $user,
            membership: $membership,
            grantedAbilities: $this->tenantPermissionMatrix->grantedAbilities($membership),
        );
    }
}
