<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappOperationsPanelTest extends TestCase
{
    use RefreshTenantDatabases;

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

        $loginResponse = $this->post($this->panelLoginUrl($tenant), [
            'email' => $user->email,
            'password' => 'password123',
        ]);
        $panelCookie = $this->cookieValue($loginResponse, (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'));

        $this->assertNotNull($panelCookie);

        $response = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), (string) $panelCookie)
            ->get($this->panelUrl($tenant));

        $response
            ->assertOk()
            ->assertSee('Mensageria WhatsApp')
            ->assertSee('Resumo Geral')
            ->assertSee('Fila Operacional')
            ->assertSee('Feed Recente')
            ->assertSee('data-whatsapp-operations-panel', false)
            ->assertSee('/api/v1/operations/whatsapp/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/feed', false);
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

        $this->post($this->panelLoginUrl($tenant), [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertSee('Sem permissao para o painel operacional');
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

        $loginResponse = $this->post($this->panelLoginUrl($tenant), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect($this->panelUrl($tenant));

        $panelCookie = $this->cookieValue($loginResponse, (string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'));

        $this->assertNotNull($panelCookie);

        $pageResponse = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), (string) $panelCookie)
            ->get($this->panelUrl($tenant));

        $pageResponse
            ->assertOk()
            ->assertSee('Mensageria WhatsApp')
            ->assertSee('/api/v1/operations/whatsapp/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/providers', false)
            ->assertSee('/api/v1/operations/whatsapp/queue', false)
            ->assertSee('/api/v1/operations/whatsapp/boundary-rejections/summary', false)
            ->assertSee('/api/v1/operations/whatsapp/boundary-rejections', false)
            ->assertSee('/api/v1/operations/whatsapp/feed', false)
            ->assertDontSee('panel-super-secret-token')
            ->assertDontSee('panel-verify-secret')
            ->assertDontSee('panel-webhook-secret');
    }

    private function panelUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp', $domain);
    }

    private function panelLoginUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/login', $domain);
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

    private function cookieValue(\Illuminate\Testing\TestResponse $response, string $name): ?string
    {
        /** @var array<int, Cookie> $cookies */
        $cookies = $response->headers->getCookies();

        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenantConnection(Tenant $tenant, \Closure $callback): mixed
    {
        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            return $callback();
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }
    }
}
