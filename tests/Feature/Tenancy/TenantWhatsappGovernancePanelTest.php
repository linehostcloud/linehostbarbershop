<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Tenant\Models\Tenant;
use Tests\Concerns\InteractsWithTenantWhatsappPanel;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappGovernancePanelTest extends TestCase
{
    use InteractsWithTenantWhatsappPanel;
    use RefreshTenantDatabases;

    public function test_governance_page_lists_tenant_scoped_automations(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-automations-a', 'barbearia-governanca-automations-a.test');
        $otherTenant = $this->provisionTenant('barbearia-governanca-automations-b', 'barbearia-governanca-automations-b.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'automation_admin',
            email: 'governanca-automations-a@test.local',
            password: 'password123',
        );

        $this->withTenantConnection($tenant, function (): void {
            app(EnsureDefaultWhatsappAutomationsAction::class)->execute();
        });

        $this->withTenantConnection($otherTenant, function (): void {
            app(EnsureDefaultWhatsappAutomationsAction::class)->execute();

            Automation::query()
                ->where('trigger_event', 'appointment_reminder')
                ->update(['name' => 'Nome de outro tenant']);
        });

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

        $this->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelGovernanceUrl($tenant))
            ->assertOk()
            ->assertSee('Automações WhatsApp')
            ->assertSee('Lembrete de Agendamento')
            ->assertSee('Reativação de Cliente Inativo')
            ->assertDontSee('Nome de outro tenant');
    }

    public function test_governance_automation_update_respects_permission(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-automation-permission', 'barbearia-governanca-automation-permission.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            permissions: ['whatsapp.operations.read', 'whatsapp.automations.read'],
            email: 'governanca-automation-permission@test.local',
            password: 'password123',
        );

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->governanceFormContext($tenant, $panelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->patch($this->panelGovernanceAutomationUpdateUrl($tenant, 'appointment_reminder'), [
                '_token' => $csrf,
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_governance_can_enable_and_disable_automation_with_audit(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-automation-toggle', 'barbearia-governanca-automation-toggle.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'automation_admin',
            email: 'governanca-automation-toggle@test.local',
            password: 'password123',
        );

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->governanceFormContext($tenant, $panelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->patch($this->panelGovernanceAutomationUpdateUrl($tenant, 'appointment_reminder'), [
                '_token' => $csrf,
                'status' => 'active',
            ])
            ->assertRedirect($this->panelGovernanceUrl($tenant));

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->patch($this->panelGovernanceAutomationUpdateUrl($tenant, 'appointment_reminder'), [
                '_token' => $csrf,
                'status' => 'inactive',
            ])
            ->assertRedirect($this->panelGovernanceUrl($tenant));

        $this->withTenantConnection($tenant, function (): void {
            $automation = Automation::query()
                ->where('trigger_event', 'appointment_reminder')
                ->sole();

            $this->assertSame('inactive', $automation->status);
        });

        $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_automation.activated')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_automation.deactivated')->count());
    }

    public function test_governance_page_lists_tenant_scoped_agent_insights(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-agent-a', 'barbearia-governanca-agent-a.test');
        $otherTenant = $this->provisionTenant('barbearia-governanca-agent-b', 'barbearia-governanca-agent-b.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'governanca-agent-a@test.local',
            password: 'password123',
        );

        $this->seedAgentInsight($tenant, [
            'title' => 'Insight do tenant atual',
            'summary' => 'Detalhe do tenant atual',
        ]);
        $this->seedAgentInsight($otherTenant, [
            'title' => 'Insight de outro tenant',
            'summary' => 'Não deve vazar',
        ]);

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

        $this->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelGovernanceUrl($tenant))
            ->assertOk()
            ->assertSee('Insights e Recomendações do Agente')
            ->assertSee('Insight do tenant atual')
            ->assertDontSee('Insight de outro tenant');
    }

    public function test_governance_can_mark_insight_as_resolved(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-resolve', 'barbearia-governanca-resolve.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'automation_admin',
            email: 'governanca-resolve@test.local',
            password: 'password123',
        );
        $insightId = $this->seedAgentInsight($tenant, [
            'title' => 'Resolver insight',
        ]);

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->governanceFormContext($tenant, $panelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->post($this->panelGovernanceAgentResolveUrl($tenant, $insightId), [
                '_token' => $csrf,
            ])
            ->assertRedirect($this->panelGovernanceUrl($tenant));

        $this->withTenantConnection($tenant, function () use ($insightId): void {
            $this->assertSame('resolved', AgentInsight::query()->findOrFail($insightId)->status);
        });

        $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_agent.insight_resolved')->count());
    }

    public function test_governance_can_mark_insight_as_ignored(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-ignore', 'barbearia-governanca-ignore.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'automation_admin',
            email: 'governanca-ignore@test.local',
            password: 'password123',
        );
        $insightId = $this->seedAgentInsight($tenant, [
            'title' => 'Ignorar insight',
        ]);

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->governanceFormContext($tenant, $panelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->post($this->panelGovernanceAgentIgnoreUrl($tenant, $insightId), [
                '_token' => $csrf,
            ])
            ->assertRedirect($this->panelGovernanceUrl($tenant));

        $this->withTenantConnection($tenant, function () use ($insightId): void {
            $this->assertSame('ignored', AgentInsight::query()->findOrFail($insightId)->status);
        });

        $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_agent.insight_ignored')->count());
    }

    public function test_governance_safe_agent_execution_respects_permission_and_audits(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-execute', 'barbearia-governanca-execute.test');
        $limitedUser = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            permissions: ['whatsapp.operations.read', 'whatsapp.agent.read'],
            email: 'governanca-execute-limited@test.local',
            password: 'password123',
        );
        $adminUser = $this->createTenantUser(
            tenant: $tenant,
            role: 'automation_admin',
            email: 'governanca-execute-admin@test.local',
            password: 'password123',
        );

        $automationId = $this->ensureAutomation($tenant, 'appointment_reminder');
        $insightId = $this->seedAgentInsight($tenant, [
            'type' => 'automation_opportunity_reminder',
            'recommendation_type' => 'enable_automation',
            'title' => 'Habilitar lembrete',
            'summary' => 'Automação pode ser habilitada com segurança.',
            'automation_id' => $automationId,
            'suggested_action' => 'enable_automation',
            'execution_mode' => 'manual_safe_action',
            'action_payload_json' => [
                'automation_id' => $automationId,
                'action' => 'enable_automation',
            ],
        ]);

        $limitedPanelCookie = $this->loginPanelAndGetCookie($tenant, $limitedUser->email, 'password123');
        ['csrf' => $limitedCsrf, 'session' => $limitedSession] = $this->governanceFormContext($tenant, $limitedPanelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $limitedPanelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $limitedSession)
            ->post($this->panelGovernanceAgentExecuteUrl($tenant, $insightId), [
                '_token' => $limitedCsrf,
            ])
            ->assertForbidden();

        $adminPanelCookie = $this->loginPanelAndGetCookie($tenant, $adminUser->email, 'password123');
        ['csrf' => $adminCsrf, 'session' => $adminSession] = $this->governanceFormContext($tenant, $adminPanelCookie);

        $this->from($this->panelGovernanceUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $adminPanelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $adminSession)
            ->post($this->panelGovernanceAgentExecuteUrl($tenant, $insightId), [
                '_token' => $adminCsrf,
            ])
            ->assertRedirect($this->panelGovernanceUrl($tenant));

        $this->withTenantConnection($tenant, function () use ($automationId, $insightId): void {
            $this->assertSame('active', Automation::query()->findOrFail($automationId)->status);
            $this->assertSame('executed', AgentInsight::query()->findOrFail($insightId)->status);
        });

        $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_agent.recommendation_executed')->count());
    }

    public function test_governance_run_history_is_rendered_consistently(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-runs', 'barbearia-governanca-runs.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'governanca-runs@test.local',
            password: 'password123',
        );

        $automationId = $this->ensureAutomation($tenant, 'appointment_reminder');
        $this->seedAutomationRun($tenant, $automationId, 'appointment_reminder', [
            'status' => 'completed',
            'candidates_found' => 7,
            'messages_queued' => 4,
            'skipped_total' => 2,
            'failed_total' => 1,
        ]);
        $this->seedAgentRun($tenant, [
            'status' => 'completed',
            'insights_created' => 2,
            'insights_refreshed' => 1,
            'insights_resolved' => 1,
            'safe_actions_executed' => 1,
        ]);

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

        $this->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelGovernanceUrl($tenant))
            ->assertOk()
            ->assertSee('Histórico Recente de Execuções de Automação')
            ->assertSee('Histórico Recente de Execuções do Agente')
            ->assertSee('appointment_reminder')
            ->assertSee('Candidatos')
            ->assertSee('Ações seguras');
    }

    public function test_governance_page_blocks_users_without_panel_permissions(): void
    {
        $tenant = $this->provisionTenant('barbearia-governanca-blocked', 'barbearia-governanca-blocked.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'finance',
            email: 'governanca-finance-blocked@test.local',
            password: 'password123',
        );

        $this->postPanelLogin($tenant, $user->email, 'password123')
            ->assertForbidden()
            ->assertSee('Sem permissão para o painel operacional');
    }

    private function loginPanelAndGetCookie(Tenant $tenant, string $email, string $password): string
    {
        $response = $this->postPanelLogin($tenant, $email, $password);
        $cookie = $this->cookieValue($response, (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'));

        $this->assertNotNull($cookie);

        return (string) $cookie;
    }

    /**
     * @return array{csrf:string,session:string}
     */
    private function governanceFormContext(Tenant $tenant, string $panelCookie): array
    {
        $response = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelGovernanceUrl($tenant));

        $response->assertOk();

        $csrf = $this->extractCsrfToken((string) $response->getContent());
        $sessionCookie = $this->cookieValue($response, (string) config('session.cookie'));

        $this->assertNotNull($csrf);
        $this->assertNotNull($sessionCookie);

        return [
            'csrf' => (string) $csrf,
            'session' => (string) $sessionCookie,
        ];
    }

    private function ensureAutomation(Tenant $tenant, string $type): string
    {
        return $this->withTenantConnection($tenant, function () use ($type): string {
            app(EnsureDefaultWhatsappAutomationsAction::class)->execute();

            return (string) Automation::query()
                ->where('trigger_event', $type)
                ->value('id');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedAgentInsight(Tenant $tenant, array $attributes = []): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $run = AgentRun::query()->create([
                'channel' => 'whatsapp',
                'status' => 'completed',
                'window_started_at' => now()->subHour(),
                'window_ended_at' => now(),
                'insights_created' => 1,
                'insights_refreshed' => 0,
                'insights_resolved' => 0,
                'insights_ignored' => 0,
                'safe_actions_executed' => 0,
                'run_context_json' => ['source' => 'test'],
                'result_json' => ['active_insights_total' => 1],
                'started_at' => now()->subMinutes(5),
                'completed_at' => now()->subMinutes(4),
            ]);

            return AgentInsight::query()->create(array_merge([
                'agent_run_id' => $run->id,
                'channel' => 'whatsapp',
                'insight_key' => 'test-insight-'.str()->random(8),
                'type' => 'provider_health_alert',
                'recommendation_type' => 'review_primary_provider',
                'status' => 'active',
                'severity' => 'high',
                'priority' => 10,
                'title' => 'Insight de teste',
                'summary' => 'Resumo do insight de teste.',
                'target_type' => 'provider_config',
                'target_id' => 'provider-config-id',
                'target_label' => 'primary/fake',
                'provider' => 'fake',
                'slot' => 'primary',
                'automation_id' => null,
                'evidence_json' => ['failures_recent' => 5, 'fallbacks_recent' => 2],
                'suggested_action' => 'review_primary_provider',
                'action_payload_json' => ['provider' => 'fake'],
                'execution_mode' => 'recommend_only',
                'first_detected_at' => now()->subMinutes(10),
                'last_detected_at' => now()->subMinutes(2),
            ], $attributes))->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedAutomationRun(Tenant $tenant, string $automationId, string $type, array $attributes = []): string
    {
        return $this->withTenantConnection($tenant, function () use ($automationId, $type, $attributes): string {
            return AutomationRun::query()->create(array_merge([
                'automation_id' => $automationId,
                'automation_type' => $type,
                'channel' => 'whatsapp',
                'status' => 'completed',
                'window_started_at' => now()->subHour(),
                'window_ended_at' => now(),
                'candidates_found' => 3,
                'messages_queued' => 2,
                'skipped_total' => 1,
                'failed_total' => 0,
                'run_context_json' => ['source' => 'test'],
                'result_json' => ['skip_reasons' => ['cooldown_active' => 1]],
                'started_at' => now()->subMinutes(15),
                'completed_at' => now()->subMinutes(14),
            ], $attributes))->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedAgentRun(Tenant $tenant, array $attributes = []): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return AgentRun::query()->create(array_merge([
                'channel' => 'whatsapp',
                'status' => 'completed',
                'window_started_at' => now()->subHour(),
                'window_ended_at' => now(),
                'insights_created' => 1,
                'insights_refreshed' => 0,
                'insights_resolved' => 0,
                'insights_ignored' => 0,
                'safe_actions_executed' => 0,
                'run_context_json' => ['source' => 'test'],
                'result_json' => ['active_insights_total' => 1],
                'started_at' => now()->subMinutes(12),
                'completed_at' => now()->subMinutes(11),
            ], $attributes))->id;
        });
    }
}
