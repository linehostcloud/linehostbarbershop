<?php

namespace Tests\Integration\Communication;

use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappProviderResolverTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_resolves_primary_and_secondary_provider_configuration_per_tenant(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-config',
            domain: 'barbearia-provider-config.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'whatsapp_cloud',
                'base_url' => 'https://graph.facebook.com',
                'api_version' => 'v22.0',
                'access_token' => 'super-secret-token',
                'phone_number_id' => '1234567890',
                'enabled' => true,
            ]);

            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'gowa',
                'base_url' => 'https://gowa.example',
                'enabled' => true,
                'settings_json' => [
                    'auth_username' => 'admin',
                    'auth_password' => 'pass',
                ],
            ]);

            $resolver = app(TenantWhatsappProviderResolver::class);
            $primary = $resolver->resolveForOutbound();
            $secondary = $resolver->resolveForOutbound('gowa');

            $this->assertSame('whatsapp_cloud', $primary->configuration->provider);
            $this->assertSame('gowa', $primary->fallbackConfiguration?->provider);
            $this->assertSame('whatsapp_cloud', $primary->provider->providerName());
            $this->assertSame('gowa', $secondary->configuration->provider);
        });
    }

    public function test_it_fails_when_requesting_a_real_provider_without_tenant_configuration(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-missing',
            domain: 'barbearia-provider-missing.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            $this->expectException(WhatsappProviderException::class);
            $this->expectExceptionMessage('nao possui configuracao ativa');

            app(TenantWhatsappProviderResolver::class)->resolveForOutbound('whatsapp_cloud');
        });
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
