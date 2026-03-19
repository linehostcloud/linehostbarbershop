<?php

namespace Tests\Concerns;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

trait InteractsWithTenantWhatsappPanel
{
    private function panelUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp', $domain);
    }

    private function panelGovernanceUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/governanca', $domain);
    }

    private function panelRelationshipUrl(Tenant $tenant, array $query = []): string
    {
        $domain = $tenant->domains()->value('domain');
        $url = sprintf('http://%s/painel/gestao/whatsapp', $domain);

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
    }

    private function panelGovernanceAutomationUpdateUrl(Tenant $tenant, string $type): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/governanca/automacoes/%s', $domain, $type);
    }

    private function panelGovernanceAgentResolveUrl(Tenant $tenant, string $insightId): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/governanca/agente/insights/%s/resolve', $domain, $insightId);
    }

    private function panelGovernanceAgentIgnoreUrl(Tenant $tenant, string $insightId): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/governanca/agente/insights/%s/ignore', $domain, $insightId);
    }

    private function panelGovernanceAgentExecuteUrl(Tenant $tenant, string $insightId): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/governanca/agente/insights/%s/execute', $domain, $insightId);
    }

    private function panelRelationshipAppointmentReminderUrl(Tenant $tenant, string $appointmentId): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/gestao/whatsapp/agendamentos/%s/lembrete', $domain, $appointmentId);
    }

    private function panelRelationshipClientReactivationUrl(Tenant $tenant, string $clientId): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/gestao/whatsapp/clientes/%s/reativacao', $domain, $clientId);
    }

    private function panelLoginUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/painel/operacoes/whatsapp/login', $domain);
    }

    private function panelLocalBrowserUrl(Tenant $tenant): string
    {
        return sprintf('http://%s/painel/operacoes/whatsapp', $this->tenantLocalBrowserHost($tenant));
    }

    private function panelLocalBrowserLoginUrl(Tenant $tenant): string
    {
        return sprintf('http://%s/painel/operacoes/whatsapp/login', $this->tenantLocalBrowserHost($tenant));
    }

    private function tenantLocalBrowserApiUrl(Tenant $tenant, string $path): string
    {
        return sprintf('http://%s/api/v1%s', $this->tenantLocalBrowserHost($tenant), $path);
    }

    private function tenantLocalBrowserHost(Tenant $tenant): string
    {
        return sprintf(
            '%s.%s',
            $tenant->slug,
            config('tenancy.identification.local_browser_domain_suffix', 'sistema-barbearia.localhost'),
        );
    }

    private function cookieValue(TestResponse $response, string $name): ?string
    {
        return $this->cookieFromResponse($response, $name)?->getValue();
    }

    private function cookieFromResponse(TestResponse $response, string $name): ?Cookie
    {
        /** @var array<int, Cookie> $cookies */
        $cookies = $response->headers->getCookies();

        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function extractCsrfToken(string $html): ?string
    {
        if (! preg_match('/name="_token" value="([^"]+)"/', $html, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function postPanelLogin(
        Tenant $tenant,
        string $email,
        string $password,
        bool $localBrowser = false,
    ): TestResponse {
        $loginUrl = $localBrowser
            ? $this->panelLocalBrowserLoginUrl($tenant)
            : $this->panelLoginUrl($tenant);
        $sessionCookieName = (string) config('session.cookie');
        $loginPage = $this->get($loginUrl);

        $loginPage->assertOk();

        $csrfToken = $this->extractCsrfToken((string) $loginPage->getContent());
        $sessionCookie = $this->cookieValue($loginPage, $sessionCookieName);

        $this->assertNotNull($csrfToken);
        $this->assertNotNull($sessionCookie);

        return $this
            ->withUnencryptedCookie($sessionCookieName, (string) $sessionCookie)
            ->post($loginUrl, [
                '_token' => $csrfToken,
                'email' => $email,
                'password' => $password,
            ]);
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
    private function panelFormContext(string $url, string $panelCookie): array
    {
        $response = $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($url);

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
