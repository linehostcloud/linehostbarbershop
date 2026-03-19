<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Tenant\Models\Tenant;
use Tests\Concerns\InteractsWithTenantWhatsappPanel;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappOperationsPanelTest extends TestCase
{
    use RefreshTenantDatabases;
    use InteractsWithTenantWhatsappPanel;

    public function test_operations_panel_redirects_to_login_when_unauthenticated(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-guest',
            domain: 'barbearia-whatsapp-panel-guest.test',
        );

        $this->get($this->panelUrl($tenant))
            ->assertRedirect($this->panelLoginUrl($tenant));

        $this->get($this->panelLoginUrl($tenant))
            ->assertOk()
            ->assertSee('Mensageria WhatsApp')
            ->assertSee('Entrar no painel');
    }

    public function test_manager_can_log_in_and_render_the_operational_panel(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-manager',
            domain: 'barbearia-whatsapp-panel-manager.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-whatsapp-panel-manager.test',
            password: 'password123',
        );

        $loginResponse = $this->postPanelLogin($tenant, $user->email, 'password123');
        $panelCookie = $this->cookieValue($loginResponse, (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'));

        $this->assertNotNull($panelCookie);

        $response = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), (string) $panelCookie)
            ->get($this->panelUrl($tenant));

        $response
            ->assertOk()
            ->assertSee('Mensageria WhatsApp')
            ->assertSee('Governança')
            ->assertSee('Resumo Operacional')
            ->assertSee('Agente Operacional')
            ->assertSee('Saúde por Provider')
            ->assertSee('Camada Determinística')
            ->assertSee('Exige Atenção Agora')
            ->assertSee('Fila Operacional')
            ->assertSee('Rejeições de Boundary')
            ->assertSee('Feed Operacional')
            ->assertSee('Deduplicação')
            ->assertSee('data-whatsapp-operations-panel', false)
            ->assertSee('data-control="provider"', false)
            ->assertSee('data-control="auto-refresh"', false)
            ->assertSee('/api/v1/operations/whatsapp/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/agent', false)
            ->assertSee('/api/v1/operations/whatsapp/feed', false);
    }

    public function test_local_panel_login_cookie_authenticates_operational_api_requests(): void
    {
        config()->set('app.env', 'local');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'sistema-barbearia.localhost');
        config()->set('session.domain', 'sistema-barbearia.localhost');

        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-local',
            domain: 'barbearia-whatsapp-panel-local.tenant.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-whatsapp-panel-local.test',
            password: 'password123',
        );

        $cookieName = (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token');
        $sessionCookieName = (string) config('session.cookie');

        $loginPage = $this->get($this->panelLocalBrowserLoginUrl($tenant));
        $loginPage->assertOk();

        $csrfToken = $this->extractCsrfToken((string) $loginPage->getContent());
        $sessionCookie = $this->cookieValue($loginPage, $sessionCookieName);

        $this->assertNotNull($csrfToken);
        $this->assertNotNull($sessionCookie);

        $loginResponse = $this->postPanelLogin($tenant, $user->email, 'password123', localBrowser: true);
        $panelCookie = $this->cookieValue($loginResponse, $cookieName);

        $loginResponse
            ->assertRedirect($this->panelLocalBrowserUrl($tenant));

        $this->assertNotNull($panelCookie);
        $this->assertSame(
            'sistema-barbearia.localhost',
            $this->cookieFromResponse($loginResponse, $cookieName)?->getDomain(),
        );

        $this
            ->withUnencryptedCookie($cookieName, (string) $panelCookie)
            ->get($this->panelLocalBrowserUrl($tenant))
            ->assertOk()
            ->assertSee('Mensageria WhatsApp');

        $this->call(
            'GET',
            '/api/v1/operations/whatsapp/summary',
            ['window' => '24h'],
            [$cookieName => (string) $panelCookie],
            [],
            [
                'HTTP_HOST' => $this->tenantLocalBrowserHost($tenant),
                'HTTP_ACCEPT' => 'application/json',
            ],
        )
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'operational_cards' => [
                        'messages_recent_total',
                        'attempts_recent_total',
                        'operational_failures_total',
                        'retry_scheduled_total',
                        'fallback_scheduled_total',
                        'fallback_executed_total',
                        'duplicate_prevented_total',
                        'duplicate_risk_total',
                        'boundary_rejections_total',
                        'pending_queue_total',
                    ],
                ],
            ]);
    }

    public function test_local_operational_api_without_panel_cookie_remains_blocked(): void
    {
        config()->set('app.env', 'local');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'sistema-barbearia.localhost');

        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-local-guest',
            domain: 'barbearia-whatsapp-panel-local-guest.tenant.test',
        );

        $this->getJson($this->tenantLocalBrowserApiUrl($tenant, '/operations/whatsapp/summary'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token de acesso ausente ou inválido.');
    }

    public function test_user_without_operational_permission_cannot_log_in_to_the_panel(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-forbidden',
            domain: 'barbearia-whatsapp-panel-forbidden.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'receptionist',
            email: 'recepcao@barbearia-whatsapp-panel-forbidden.test',
            password: 'password123',
        );

        $this->postPanelLogin($tenant, $user->email, 'password123')
            ->assertForbidden()
            ->assertSee('Sem permissão para o painel operacional');
    }

    public function test_panel_shell_references_only_aggregated_operational_endpoints_and_does_not_expose_sensitive_data(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-panel-safe',
            domain: 'barbearia-whatsapp-panel-safe.test',
        );
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor@barbearia-whatsapp-panel-safe.test',
            password: 'password123',
        );

        $this->createDirectConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'whatsapp_cloud',
            'access_token' => 'panel-super-secret-token',
            'verify_token' => 'panel-verify-secret',
            'webhook_secret' => 'panel-webhook-secret',
            'phone_number_id' => '123456789',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => true,
        ]);

        $loginResponse = $this->postPanelLogin($tenant, $user->email, 'password123')
            ->assertRedirect($this->panelUrl($tenant));

        $panelCookie = $this->cookieValue($loginResponse, (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'));

        $this->assertNotNull($panelCookie);

        $pageResponse = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), (string) $panelCookie)
            ->get($this->panelUrl($tenant));

        $pageResponse
            ->assertOk()
            ->assertSee('Mensageria WhatsApp')
            ->assertSee('/api/v1/operations/whatsapp/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/agent', false)
            ->assertSee('/api/v1/operations/whatsapp/providers', false)
            ->assertSee('/api/v1/operations/whatsapp/queue', false)
            ->assertSee('/api/v1/operations/whatsapp/boundary-rejections/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/boundary-rejections', false)
            ->assertSee('/api/v1/operations/whatsapp/feed', false)
            ->assertSee('Camada Determinística')
            ->assertSee('Roteamento Inteligente')
            ->assertDontSee('panel-super-secret-token')
            ->assertDontSee('panel-verify-secret')
            ->assertDontSee('panel-webhook-secret');
    }
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDirectConfig(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return WhatsappProviderConfig::query()->create(array_merge([
                'slot' => 'primary',
                'provider' => 'fake',
                'timeout_seconds' => 10,
                'enabled_capabilities_json' => ['text', 'healthcheck'],
                'enabled' => true,
            ], $attributes))->id;
        });
    }
}
