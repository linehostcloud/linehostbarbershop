<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\BuildLandlordDashboardDataAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Domain\Tenant\Models\Tenant;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class BuildLandlordDashboardDataActionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_dashboard_builder_aggregates_status_onboarding_operational_attention_and_recent_activity(): void
    {
        $activeTenant = $this->provisionTenant('barbearia-ativa-dashboard', 'barbearia-ativa-dashboard.saas.test');
        $this->createTenantUser($activeTenant, email: 'owner-ativo@test.local');
        $activeTenant->forceFill([
            'status' => 'active',
            'onboarding_stage' => 'provisioned',
        ])->save();

        $pendingTenant = $this->createPendingTenant('barbearia-trial-pendente-dashboard');
        $suspendedTenant = $this->provisionTenant('barbearia-suspensa-dashboard', 'barbearia-suspensa-dashboard.saas.test');
        $this->createTenantUser($suspendedTenant, email: 'owner-suspenso@test.local');
        $suspendedTenant->forceFill([
            'status' => 'suspended',
            'onboarding_stage' => 'completed',
            'suspended_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'action' => 'landlord_tenant.status_changed',
            'before_json' => ['status' => 'active'],
            'after_json' => ['status' => 'suspended'],
            'metadata_json' => ['reason' => 'Suspensão operacional para auditoria.'],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $activeTenant->id,
            'action' => 'landlord_tenant.basics_updated',
            'before_json' => ['timezone' => 'America/Sao_Paulo'],
            'after_json' => ['timezone' => 'America/Fortaleza'],
            'metadata_json' => ['changed_fields' => ['timezone']],
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'api',
            'outcome' => 'blocked',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'endpoint' => 'api/v1/auth/me',
            'method' => 'GET',
            'http_status' => 423,
            'correlation_id' => (string) fake()->uuid(),
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subHours(1),
        ]);

        TenantOperationalBlockAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'channel' => 'command',
            'outcome' => 'skipped',
            'reason_code' => 'tenant_status_runtime_enforcement',
            'surface' => 'tenancy:process-outbox',
            'correlation_id' => (string) fake()->uuid(),
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(30),
        ]);

        BoundaryRejectionAudit::query()->create([
            'tenant_id' => $suspendedTenant->id,
            'tenant_slug' => $suspendedTenant->slug,
            'direction' => 'webhook',
            'endpoint' => 'webhooks/whatsapp/fake',
            'method' => 'POST',
            'host' => 'barbearia-suspensa-dashboard.saas.test',
            'code' => WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value,
            'message' => 'Webhook ignorado porque o tenant está suspenso.',
            'http_status' => 202,
            'correlation_id' => (string) fake()->uuid(),
            'context_json' => ['tenant_status' => 'suspended'],
            'occurred_at' => now()->subMinutes(20),
        ]);

        $dashboard = app(BuildLandlordDashboardDataAction::class)->execute();

        $statusTotals = collect($dashboard['headline']['status_totals'])->keyBy('code');
        $onboardingTotals = collect($dashboard['headline']['onboarding_totals'])->keyBy('code');

        $this->assertSame(3, $dashboard['headline']['total_tenants']);
        $this->assertSame(1, $statusTotals['trial']['count']);
        $this->assertSame(1, $statusTotals['active']['count']);
        $this->assertSame(1, $statusTotals['suspended']['count']);
        $this->assertSame(1, $onboardingTotals['created']['count']);
        $this->assertSame(1, $onboardingTotals['provisioned']['count']);
        $this->assertSame(1, $onboardingTotals['completed']['count']);
        $this->assertSame(1, $dashboard['operational']['pending_tenants_count']);
        $this->assertSame(1, $dashboard['operational']['suspended_with_pressure_count']);
        $this->assertSame('barbearia-trial-pendente-dashboard', $dashboard['pending_tenants'][0]['slug']);
        $this->assertSame('Banco pendente', $dashboard['pending_tenants'][0]['provisioning']['label']);
        $this->assertSame('barbearia-suspensa-dashboard', $dashboard['suspended_pressure'][0]['slug']);
        $this->assertSame(3, $dashboard['suspended_pressure'][0]['total_blocks']);
        $this->assertSame(3, $dashboard['suspended_pressure'][0]['affected_channels_count']);
        $this->assertContains('API tenant bloqueada', $dashboard['suspended_pressure'][0]['channels']);
        $this->assertTrue(collect($dashboard['recent_activity'])->contains(
            fn (array $item): bool => $item['label'] === 'Status do tenant atualizado'
                && $item['tenant']['label'] === 'Barbearia Suspensa Dashboard'
        ));
        $this->assertSame('Suspensão com pressão recente', $dashboard['attention_items'][0]['label']);
    }

    private function createPendingTenant(string $slug): Tenant
    {
        $databasePath = $this->trackTenantDatabase(sprintf('tenant_%s.sqlite', str_replace('-', '_', $slug)));

        return Tenant::query()->create([
            'legal_name' => strtoupper(str_replace('-', ' ', $slug)).' LTDA',
            'trade_name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'niche' => 'barbershop',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'status' => 'trial',
            'onboarding_stage' => 'created',
            'database_name' => $databasePath,
            'database_host' => null,
            'database_port' => null,
            'database_username' => null,
            'database_password_encrypted' => null,
        ]);
    }
}
