<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Auth\IssueTenantAccessTokenAction;
use App\Application\Actions\Tenancy\GuardTenantOperationalCommandAction;
use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Infrastructure\Tenancy\Exceptions\TenantOperationalAccessDenied;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantOperationalEnforcementConventionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_tenant_aware_routes_keep_tenant_resolve_middleware(): void
    {
        $tenantAwareRoutes = collect(Route::getRoutes())
            ->filter(function ($route): bool {
                $uri = ltrim($route->uri(), '/');

                if ($uri === 'api/v1/ping') {
                    return false;
                }

                return str_starts_with($uri, 'api/v1/')
                    || str_starts_with($uri, 'painel/operacoes/whatsapp')
                    || str_starts_with($uri, 'painel/gestao/whatsapp')
                    || str_starts_with($uri, 'webhooks/whatsapp/');
            })
            ->values();

        $this->assertGreaterThan(0, $tenantAwareRoutes->count());

        $missingResolver = $tenantAwareRoutes
            ->filter(fn ($route): bool => ! in_array('tenant.resolve', $route->gatherMiddleware(), true))
            ->map(fn ($route): string => sprintf('%s %s', implode('|', $route->methods()), $route->uri()))
            ->values()
            ->all();

        $this->assertSame([], $missingResolver, 'Rotas tenant-aware sem tenant.resolve: '.implode(', ', $missingResolver));
    }

    public function test_guard_tenant_operational_command_action_records_skip_for_suspended_tenant(): void
    {
        $tenant = $this->provisionTenant('barbearia-command-guard', 'barbearia-command-guard.test');
        $tenant->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
        ])->save();

        $allowed = app(GuardTenantOperationalCommandAction::class)->execute($tenant, 'tenancy:process-outbox');

        $this->assertFalse($allowed);

        $audit = TenantOperationalBlockAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'command')
            ->latest('occurred_at')
            ->firstOrFail();

        $this->assertSame('tenancy:process-outbox', $audit->surface);
        $this->assertSame('skipped', $audit->outcome);
        $this->assertSame('tenant_status_runtime_enforcement', $audit->reason_code);
    }

    public function test_issue_tenant_access_token_action_records_credential_block_for_suspended_tenant(): void
    {
        $tenant = $this->provisionTenant('barbearia-token-guard', 'barbearia-token-guard.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-token-guard.test',
        );
        $tenant->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
        ])->save();

        try {
            app(IssueTenantAccessTokenAction::class)->execute($user, $tenant, [
                'name' => 'internal-issue',
            ]);

            $this->fail('A emissão de token deveria falhar para tenant suspenso.');
        } catch (TenantOperationalAccessDenied) {
            // expected
        }

        $audit = TenantOperationalBlockAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'credential_issue')
            ->latest('occurred_at')
            ->firstOrFail();

        $this->assertSame(IssueTenantAccessTokenAction::class, $audit->surface);
        $this->assertSame('blocked', $audit->outcome);
        $this->assertSame('internal-issue', data_get($audit->context_json, 'token_name'));
    }
}
