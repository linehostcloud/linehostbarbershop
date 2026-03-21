<?php

namespace App\Application\Actions\Auth;

use App\Application\Actions\Observability\RecordTenantOperationalBlockAuditAction;
use App\Application\Actions\Tenancy\EnsureTenantOperationalAccessAction;
use App\Application\DTOs\IssuedTenantAccessToken;
use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class IssueTenantAccessTokenAction
{
    public function __construct(
        private readonly EnsureTenantOperationalAccessAction $ensureTenantOperationalAccess,
        private readonly RecordTenantOperationalBlockAuditAction $recordOperationalBlockAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function execute(User $user, Tenant $tenant, array $context = []): IssuedTenantAccessToken
    {
        try {
            $this->ensureTenantOperationalAccess->execute($tenant);
        } catch (TenantOperationalAccessDenied $exception) {
            $this->recordOperationalBlockAudit->execute(
                tenant: $tenant,
                channel: 'credential_issue',
                outcome: 'blocked',
                reasonCode: 'tenant_status_runtime_enforcement',
                surface: static::class,
                context: [
                    'tenant_status' => $tenant->status,
                    'enforcement_policy' => 'tenant_status_runtime_enforcement',
                    'message' => $exception->getMessage(),
                    'user_id' => $user->getKey(),
                    'token_name' => $context['name'] ?? 'api',
                ],
            );

            throw $exception;
        }

        $membership = $user->memberships()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            throw new RuntimeException('Nao e possivel emitir token para um usuario sem membership ativa no tenant informado.');
        }

        $plainToken = Str::random(64);

        $accessToken = UserAccessToken::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'name' => $context['name'] ?? 'api',
            'token_hash' => hash('sha256', $plainToken),
            'abilities_json' => $context['abilities'] ?? ['*'],
            'expires_at' => $this->resolveExpiration(),
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
        ]);

        return new IssuedTenantAccessToken(
            accessToken: $accessToken,
            plainTextToken: sprintf('%s|%s', $accessToken->id, $plainToken),
        );
    }

    private function resolveExpiration(): ?Carbon
    {
        $ttlMinutes = (int) config('auth.access_tokens.ttl_minutes', 10080);

        if ($ttlMinutes <= 0) {
            return null;
        }

        return now()->addMinutes($ttlMinutes);
    }
}
