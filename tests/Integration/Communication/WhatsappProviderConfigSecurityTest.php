<?php

namespace Tests\Integration\Communication;

use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class WhatsappProviderConfigSecurityTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_encrypts_sensitive_provider_fields_at_rest(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-security',
            domain: 'barbearia-provider-security.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            $configuration = WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'gowa',
                'base_url' => 'https://gowa.example',
                'api_key' => 'api-key-secreta',
                'access_token' => 'access-token-secreto',
                'webhook_secret' => 'webhook-secret',
                'verify_token' => 'verify-token',
                'settings_json' => [
                    'auth_username' => 'admin',
                    'auth_password' => 'senha-super-secreta',
                ],
                'enabled' => true,
            ]);

            $raw = (array) DB::connection('tenant')
                ->table('whatsapp_provider_configs')
                ->where('id', $configuration->id)
                ->first();

            $this->assertNotSame('api-key-secreta', $raw['api_key']);
            $this->assertNotSame('access-token-secreto', $raw['access_token']);
            $this->assertNotSame('webhook-secret', $raw['webhook_secret']);
            $this->assertNotSame('verify-token', $raw['verify_token']);
            $this->assertStringNotContainsString('senha-super-secreta', (string) $raw['settings_json']);

            $loaded = WhatsappProviderConfig::query()->findOrFail($configuration->id);

            $this->assertSame('api-key-secreta', $loaded->api_key);
            $this->assertSame('access-token-secreto', $loaded->access_token);
            $this->assertSame('webhook-secret', $loaded->webhook_secret);
            $this->assertSame('verify-token', $loaded->verify_token);
            $this->assertSame('senha-super-secreta', $loaded->basicAuthPassword());
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
