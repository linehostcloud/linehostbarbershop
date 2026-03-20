<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\EnsureLandlordTenantDefaultAutomationsAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordTenantDetailPanelTest extends TestCase
{
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
        config()->set('tenancy.provisioning.database_prefix', $this->testDatabaseDirectory.DIRECTORY_SEPARATOR.'tenant_');
        config()->set('tenancy.provisioning.default_domain_suffix', 'saas.test');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'saas.test');
    }

    public function test_landlord_tenant_detail_requires_authentication(): void
    {
        $tenant = $this->provisionTenant('barbearia-detalhe-guest', 'barbearia-detalhe-guest.saas.test');

        $this->get(route('landlord.tenants.show', $tenant))
            ->assertRedirect(route('login'));
    }

    public function test_landlord_tenant_detail_displays_correct_tenant_and_operational_status(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-detalhe', 'barbearia-detalhe.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-outra', 'barbearia-outra.saas.test');
        $this->createTenantUser($tenant, email: 'owner-detalhe@test.local');
        $this->createTenantUser($otherTenant, email: 'owner-outra@test.local');

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Barbearia Detalhe')
            ->assertSee('barbearia-detalhe')
            ->assertSee('Governança de estado')
            ->assertSee('Status atual')
            ->assertSee('Onboarding atual')
            ->assertSee('Suspender tenant')
            ->assertSee('Nenhuma transição de onboarding está disponível para o estágio atual.')
            ->assertSee('Saúde operacional')
            ->assertSee('Banco do tenant')
            ->assertSee('Schema mínimo')
            ->assertSee('Automações default')
            ->assertSee('Dados básicos mínimos')
            ->assertSee('Atividade recente')
            ->assertSee('Dados básicos')
            ->assertSee('Salvar dados básicos')
            ->assertSee('Adicionar domínio')
            ->assertSee('owner-detalhe@test.local')
            ->assertDontSee('barbearia-outra');
    }

    public function test_landlord_tenant_detail_displays_recent_activity_for_the_selected_tenant_only(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-atividade', 'barbearia-atividade.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-outra-atividade', 'barbearia-outra-atividade.saas.test');

        $this->recordLandlordAuditLog(
            tenant: $tenant,
            actor: $admin,
            action: 'landlord_tenant.provisioned_via_web',
            after: [
                'slug' => 'barbearia-atividade',
                'domain' => 'audit-correto.saas.test',
            ],
            metadata: [
                'owner_email' => 'owner-atividade@test.local',
            ],
        );

        $this->recordLandlordAuditLog(
            tenant: $tenant,
            actor: $admin,
            action: 'landlord_tenant.basics_updated',
            before: [
                'trade_name' => 'Barbearia Atividade',
            ],
            after: [
                'trade_name' => 'Barbearia Atividade Nova',
                'timezone' => 'America/Fortaleza',
            ],
            metadata: [
                'changed_fields' => ['trade_name', 'timezone'],
            ],
        );

        $this->recordLandlordAuditLog(
            tenant: $otherTenant,
            actor: $admin,
            action: 'landlord_tenant.domain_added',
            after: [
                'domain' => 'nao-deve-aparecer.audit.test',
                'is_primary' => false,
            ],
        );

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Atividade recente')
            ->assertSee('Tenant provisionado')
            ->assertSee('Dados básicos atualizados')
            ->assertSee('audit-correto.saas.test')
            ->assertSee('owner-atividade@test.local')
            ->assertSee('Campos atualizados: Nome fantasia, Timezone.')
            ->assertSee('saas-admin@test.local')
            ->assertDontSee('nao-deve-aparecer.audit.test');
    }

    public function test_landlord_can_update_tenant_basics_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-edicao', 'barbearia-edicao.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-intocada', 'barbearia-intocada.saas.test');

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.update-basics', $tenant), [
                'trade_name' => 'Barbearia Premium',
                'legal_name' => 'Barbearia Premium LTDA',
                'timezone' => 'America/Fortaleza',
                'currency' => 'usd',
            ])
            ->assertOk()
            ->assertSee('Dados básicos do tenant &quot;Barbearia Premium&quot; atualizados com sucesso.', false);

        $tenant->refresh();
        $otherTenant->refresh();

        $this->assertSame('Barbearia Premium', $tenant->trade_name);
        $this->assertSame('Barbearia Premium LTDA', $tenant->legal_name);
        $this->assertSame('America/Fortaleza', $tenant->timezone);
        $this->assertSame('USD', $tenant->currency);

        $this->assertSame('Barbearia Intocada', $otherTenant->trade_name);
        $this->assertSame('America/Sao_Paulo', $otherTenant->timezone);
        $this->assertSame('BRL', $otherTenant->currency);

        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.basics_updated')->count());
    }

    public function test_landlord_tenant_basics_update_validates_input(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-validacao', 'barbearia-validacao.saas.test');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.update-basics', $tenant), [
                'trade_name' => '',
                'legal_name' => 'Razao Qualquer',
                'timezone' => 'timezone-invalida',
                'currency' => 'real',
            ])
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHasErrorsIn('tenantBasics', ['trade_name', 'timezone', 'currency']);

        $tenant->refresh();

        $this->assertSame('Barbearia Validacao', $tenant->trade_name);
        $this->assertSame('America/Sao_Paulo', $tenant->timezone);
        $this->assertSame('BRL', $tenant->currency);
        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.basics_updated')->count());
    }

    public function test_landlord_can_change_tenant_status_via_detail_panel_with_audit(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-status', 'barbearia-status.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-status-intocada', 'barbearia-status-intocada.saas.test');

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.change-status', $tenant), [
                'status' => 'suspended',
                'status_reason' => 'Suspensao administrativa para revisar a operacao.',
            ])
            ->assertOk()
            ->assertSee('Status do tenant &quot;Barbearia Status&quot; atualizado para &quot;suspenso&quot;.', false)
            ->assertSee('Status do tenant atualizado')
            ->assertSee('Motivo: Suspensao administrativa para revisar a operacao.');

        $tenant->refresh();
        $otherTenant->refresh();

        $this->assertSame('suspended', $tenant->status);
        $this->assertNotNull($tenant->suspended_at);
        $this->assertSame('active', $otherTenant->status);

        $auditLog = AuditLog::query()
            ->where('action', 'landlord_tenant.status_changed')
            ->sole();

        $this->assertSame($tenant->id, $auditLog->tenant_id);
        $this->assertSame($admin->id, $auditLog->actor_user_id);
        $this->assertSame('active', data_get($auditLog->before_json, 'status'));
        $this->assertSame('suspended', data_get($auditLog->after_json, 'status'));
        $this->assertSame('active', data_get($auditLog->metadata_json, 'from'));
        $this->assertSame('suspended', data_get($auditLog->metadata_json, 'to'));
        $this->assertSame('Suspensao administrativa para revisar a operacao.', data_get($auditLog->metadata_json, 'reason'));
    }

    public function test_landlord_status_transition_blocks_invalid_target_for_current_state(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-status-invalido', 'barbearia-status-invalido.saas.test');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.change-status', $tenant), [
                'status' => 'trial',
                'status_reason' => 'Tentativa manual de retorno para trial.',
            ])
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHasErrorsIn('tenantStatusTransition', ['status']);

        $tenant->refresh();

        $this->assertSame('active', $tenant->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.status_changed')->count());
    }

    public function test_landlord_status_transition_requires_reason(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-status-motivo', 'barbearia-status-motivo.saas.test');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.change-status', $tenant), [
                'status' => 'suspended',
                'status_reason' => '',
            ])
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHasErrorsIn('tenantStatusTransition', ['status_reason']);

        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.status_changed')->count());
    }

    public function test_landlord_can_advance_tenant_onboarding_with_audit(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->prepareTenantForProvisioningTransition('barbearia-onboarding');
        $otherTenant = $this->prepareTenantForProvisioningTransition('barbearia-onboarding-intocado');

        $this->ensureTenantDefaultAutomations($tenant, $admin);

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.transition-onboarding-stage', $tenant), [
                'onboarding_stage' => 'provisioned',
                'onboarding_transition_reason' => 'Provisionamento validado manualmente pelo landlord.',
            ])
            ->assertOk()
            ->assertSee('Onboarding do tenant &quot;Barbearia Onboarding&quot; atualizado para &quot;provisionado&quot;.', false)
            ->assertSee('Onboarding atualizado')
            ->assertSee('Motivo: Provisionamento validado manualmente pelo landlord.');

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.transition-onboarding-stage', $tenant), [
                'onboarding_stage' => 'completed',
                'onboarding_transition_reason' => 'Checklist operacional concluido pelo landlord.',
            ])
            ->assertOk()
            ->assertSee('Onboarding do tenant &quot;Barbearia Onboarding&quot; atualizado para &quot;concluído&quot;.', false)
            ->assertSee('Onboarding atualizado')
            ->assertSee('Motivo: Checklist operacional concluido pelo landlord.');

        $tenant->refresh();
        $otherTenant->refresh();

        $this->assertSame('completed', $tenant->onboarding_stage);
        $this->assertSame('created', $otherTenant->onboarding_stage);

        $auditLogs = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'landlord_tenant.onboarding_stage_transitioned')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $auditLogs);
        $this->assertSame($admin->id, $auditLogs[0]->actor_user_id);
        $this->assertSame('created', data_get($auditLogs[0]->before_json, 'onboarding_stage'));
        $this->assertSame('provisioned', data_get($auditLogs[0]->after_json, 'onboarding_stage'));
        $this->assertSame('Provisionamento validado manualmente pelo landlord.', data_get($auditLogs[0]->metadata_json, 'reason'));
        $this->assertSame('provisioned', data_get($auditLogs[1]->before_json, 'onboarding_stage'));
        $this->assertSame('completed', data_get($auditLogs[1]->after_json, 'onboarding_stage'));
        $this->assertSame('Checklist operacional concluido pelo landlord.', data_get($auditLogs[1]->metadata_json, 'reason'));
    }

    public function test_landlord_onboarding_transition_blocks_progress_when_prerequisites_are_missing(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createLandlordTenantWithoutSchema('barbearia-onboarding-bloqueado', 'barbearia-onboarding-bloqueado.saas.test');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.transition-onboarding-stage', $tenant), [
                'onboarding_stage' => 'provisioned',
                'onboarding_transition_reason' => 'Tentativa de avancar onboarding sem schema minimo.',
            ])
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHasErrorsIn('tenantOnboardingTransition', ['onboarding_stage']);

        $tenant->refresh();

        $this->assertSame('created', $tenant->onboarding_stage);
        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.onboarding_stage_transitioned')->count());
    }

    public function test_landlord_onboarding_transition_requires_reason(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->prepareTenantForProvisioningTransition('barbearia-onboarding-motivo');

        $this->actingAs($admin)
            ->from(route('landlord.tenants.show', $tenant))
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.transition-onboarding-stage', $tenant), [
                'onboarding_stage' => 'provisioned',
                'onboarding_transition_reason' => '',
            ])
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHasErrorsIn('tenantOnboardingTransition', ['onboarding_transition_reason']);

        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.onboarding_stage_transitioned')->count());
    }

    public function test_landlord_detail_only_shows_governance_actions_allowed_for_the_current_state(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->prepareTenantForProvisioningTransition('barbearia-governanca-ui');

        $this->ensureTenantDefaultAutomations($tenant, $admin);

        $tenant->forceFill([
            'status' => 'suspended',
            'onboarding_stage' => 'provisioned',
        ])->save();

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Governança de estado')
            ->assertSee('Reativar tenant')
            ->assertDontSee('Ativar tenant')
            ->assertDontSee('Suspender tenant')
            ->assertSee('Concluir onboarding')
            ->assertDontSee('Marcar como provisionado');
    }

    public function test_landlord_can_add_domain_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-dominios', 'barbearia-dominios.saas.test');

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.domains.store', $tenant), [
                'domain' => 'agenda.barbearia-dominios.saas.test',
            ])
            ->assertOk()
            ->assertSee('Domínio &quot;agenda.barbearia-dominios.saas.test&quot; adicionado ao tenant &quot;Barbearia Dominios&quot;.', false);

        $tenant->refresh();

        $this->assertTrue($tenant->domains()->where('domain', 'agenda.barbearia-dominios.saas.test')->exists());
        $this->assertSame(2, $tenant->domains()->count());
        $this->assertSame(1, $tenant->domains()->where('is_primary', true)->count());
        $this->assertSame('barbearia-dominios.saas.test', $tenant->domains()->where('is_primary', true)->value('domain'));
        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.domain_added')->count());
    }

    public function test_landlord_can_define_primary_domain_and_normalize_invalid_domain_state(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-principal', 'barbearia-principal.saas.test');
        $secondaryDomain = $tenant->domains()->create([
            'domain' => 'agenda.barbearia-principal.saas.test',
            'type' => 'admin',
            'is_primary' => true,
            'ssl_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->followingRedirects()
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.domains.set-primary', [$tenant, $secondaryDomain]))
            ->assertOk()
            ->assertSee('Domínio principal do tenant &quot;Barbearia Principal&quot; atualizado para &quot;agenda.barbearia-principal.saas.test&quot;.', false);

        $tenant->refresh();
        $secondaryDomain->refresh();

        $this->assertSame(1, $tenant->domains()->where('is_primary', true)->count());
        $this->assertTrue($secondaryDomain->is_primary);
        $this->assertSame('agenda.barbearia-principal.saas.test', $tenant->domains()->where('is_primary', true)->value('domain'));
        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.primary_domain_updated')->count());
    }

    public function test_landlord_domain_creation_rejects_central_domains(): void
    {
        config()->set('tenancy.central_domains', ['central.saas.test']);

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-central', 'barbearia-central.saas.test');
        $showUrl = 'http://central.saas.test'.route('landlord.tenants.show', $tenant, false);
        $storeDomainUrl = 'http://central.saas.test'.route('landlord.tenants.domains.store', $tenant, false);

        $this->actingAs($admin)
            ->from($showUrl)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post($storeDomainUrl, [
                'domain' => 'central.saas.test',
            ])
            ->assertRedirect($showUrl)
            ->assertSessionHasErrorsIn('tenantDomains', ['domain']);

        $this->assertFalse($tenant->domains()->where('domain', 'central.saas.test')->exists());
        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.domain_added')->count());
    }

    public function test_landlord_primary_domain_action_only_targets_domains_from_the_selected_tenant(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-alvo', 'barbearia-alvo.saas.test');
        $otherTenant = $this->provisionTenant('barbearia-externa', 'barbearia-externa.saas.test');
        $externalDomain = $otherTenant->domains()->create([
            'domain' => 'agenda.barbearia-externa.saas.test',
            'type' => 'admin',
            'is_primary' => false,
            'ssl_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.domains.set-primary', [$tenant, $externalDomain]))
            ->assertNotFound();

        $this->assertSame('barbearia-alvo.saas.test', $tenant->domains()->where('is_primary', true)->value('domain'));
        $this->assertSame('barbearia-externa.saas.test', $otherTenant->domains()->where('is_primary', true)->value('domain'));
        $this->assertSame(0, AuditLog::query()->where('action', 'landlord_tenant.primary_domain_updated')->count());
    }

    public function test_landlord_tenant_detail_reports_schema_pending_when_minimum_schema_is_missing(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createLandlordTenantWithoutSchema('barbearia-schema-pendente', 'barbearia-schema-pendente.saas.test');

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Schema pendente')
            ->assertSee('Tabelas mínimas ausentes')
            ->assertSee('clients')
            ->assertSee('appointments')
            ->assertSee('messages');
    }

    public function test_landlord_can_sync_tenant_schema_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createLandlordTenantWithoutSchema('barbearia-schema-sync', 'barbearia-schema-sync.saas.test');

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.sync-schema', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertTrue(Schema::connection('tenant')->hasTable('clients'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('appointments'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('messages'));
            $this->assertSame(count(WhatsappAutomationType::values()), Automation::query()->count());
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.schema_sync_requested')->count());
    }

    public function test_landlord_can_ensure_default_automations_via_detail_panel(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-defaults', 'barbearia-defaults.saas.test');
        $this->createTenantUser($tenant, email: 'owner-defaults@test.local');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            Automation::query()->delete();
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.ensure-default-automations', $tenant))
            ->assertRedirect(route('landlord.tenants.show', $tenant))
            ->assertSessionHas('status');

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertSame(count(WhatsappAutomationType::values()), Automation::query()->count());
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'landlord_tenant.default_automations_ensured')->count());
    }

    public function test_landlord_tenant_detail_requires_central_domain(): void
    {
        config()->set('tenancy.central_domains', ['central.saas.test']);

        $admin = $this->createLandlordAdmin();
        $tenant = $this->provisionTenant('barbearia-central-domain', 'barbearia-central-domain.saas.test');

        $this->actingAs($admin)
            ->withServerVariables(['HTTP_HOST' => 'tenant.saas.test'])
            ->get(route('landlord.tenants.show', $tenant, false))
            ->assertNotFound();
    }

    public function test_non_admin_user_cannot_execute_landlord_detail_actions(): void
    {
        $user = User::factory()->create([
            'name' => 'Operador Sem Acesso',
            'email' => 'operador-sem-acesso@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
        $tenant = $this->provisionTenant('barbearia-bloqueio', 'barbearia-bloqueio.saas.test');

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.sync-schema', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.update-basics', $tenant), [
                'trade_name' => 'Barbearia Sem Acesso',
                'legal_name' => 'Barbearia Sem Acesso LTDA',
                'timezone' => 'America/Sao_Paulo',
                'currency' => 'BRL',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.change-status', $tenant), [
                'status' => 'suspended',
                'status_reason' => 'Tentativa sem permissao para mudar status.',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch(route('landlord.tenants.transition-onboarding-stage', $tenant), [
                'onboarding_stage' => 'provisioned',
                'onboarding_transition_reason' => 'Tentativa sem permissao para mudar onboarding.',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.domains.store', $tenant), [
                'domain' => 'agenda.barbearia-bloqueio.saas.test',
            ])
            ->assertForbidden();

        $domain = $tenant->domains()->firstOrFail();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('landlord.tenants.domains.set-primary', [$tenant, $domain]))
            ->assertForbidden();
    }

    private function createLandlordAdmin(): User
    {
        return User::factory()->create([
            'name' => 'SaaS Admin',
            'email' => 'saas-admin@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
    }

    private function prepareTenantForProvisioningTransition(string $slug): Tenant
    {
        $tenant = $this->provisionTenant($slug, sprintf('%s.saas.test', $slug));
        $this->createTenantUser($tenant, email: sprintf('%s-owner@test.local', $slug));

        $tenant->forceFill([
            'onboarding_stage' => 'created',
        ])->save();

        return $tenant->fresh(['domains', 'memberships.user']);
    }

    private function ensureTenantDefaultAutomations(Tenant $tenant, User $actor): void
    {
        app(EnsureLandlordTenantDefaultAutomationsAction::class)->execute($tenant, $actor);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordLandlordAuditLog(
        Tenant $tenant,
        User $actor,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): void {
        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor->id,
            'auditable_type' => 'tenant',
            'auditable_id' => $tenant->id,
            'action' => $action,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata,
        ]);
    }

    private function createLandlordTenantWithoutSchema(string $slug, string $domain): Tenant
    {
        $databasePath = $this->trackTenantDatabase(sprintf('%s.sqlite', $slug));
        touch($databasePath);
        chmod($databasePath, 0666);

        $tenant = Tenant::query()->create([
            'legal_name' => 'BARBEARIA SCHEMA LTDA',
            'trade_name' => str($slug)->replace('-', ' ')->title()->value(),
            'slug' => $slug,
            'niche' => 'barbershop',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'status' => 'active',
            'onboarding_stage' => 'created',
            'database_name' => $databasePath,
            'database_host' => null,
            'database_port' => null,
            'database_username' => null,
            'database_password_encrypted' => null,
            'plan_code' => 'starter',
            'activated_at' => now(),
        ]);

        $tenant->domains()->create([
            'domain' => $domain,
            'type' => 'admin',
            'is_primary' => true,
            'ssl_status' => 'pending',
        ]);

        $owner = User::factory()->create([
            'name' => 'Owner Schema',
            'email' => sprintf('%s@schema.test', $slug),
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_primary' => true,
            'accepted_at' => now(),
        ]);

        return $tenant->fresh(['domains', 'memberships.user']);
    }
}
