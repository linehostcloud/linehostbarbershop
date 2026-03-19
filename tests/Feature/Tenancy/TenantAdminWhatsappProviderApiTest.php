<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantAdminWhatsappProviderApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_listing_does_not_leak_secrets(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-list',
            domain: 'barbearia-provider-list.test',
        );

        $this->createDirectConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'access_token' => 'cloud-super-secret-token',
            'phone_number_id' => '123456789',
            'verify_token' => 'verify-super-secret',
            'webhook_secret' => 'webhook-super-secret',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => true,
        ]);

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.slot', 'primary')
            ->assertJsonPath('data.0.provider', 'whatsapp_cloud')
            ->assertJsonPath('data.0.secret_presence.has_access_token', true)
            ->assertJsonPath('data.0.secret_presence.has_verify_token', true)
            ->assertJsonPath('data.0.secret_presence.has_webhook_secret', true);

        $this->assertStringNotContainsString('cloud-super-secret-token', $response->getContent());
        $this->assertStringNotContainsString('verify-super-secret', $response->getContent());
        $this->assertStringNotContainsString('webhook-super-secret', $response->getContent());
    }

    public function test_owner_can_create_a_valid_provider_configuration_and_view_safe_detail(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-create',
            domain: 'barbearia-provider-create.test',
        );
        $owner = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-provider-create.test',
        );

        $createResponse = $this->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'), $this->validWhatsappCloudPayload());

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.slot', 'primary')
            ->assertJsonPath('data.provider', 'whatsapp_cloud')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.secret_presence.has_access_token', true)
            ->assertJsonPath('data.secret_presence.has_verify_token', true)
            ->assertJsonPath('data.secret_presence.has_webhook_secret', true);

        $this->assertStringNotContainsString('cloud-create-secret', $createResponse->getContent());
        $this->assertStringNotContainsString('verify-create-secret', $createResponse->getContent());
        $this->assertStringNotContainsString('webhook-create-secret', $createResponse->getContent());

        $this->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary'))
            ->assertOk()
            ->assertJsonPath('data.retry_profile.max_attempts', 4)
            ->assertJsonPath('data.secret_presence.has_access_token', true)
            ->assertJsonPath('data.secret_presence.has_verify_token', true)
            ->assertJsonPath('data.secret_presence.has_webhook_secret', true);

        $audit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame('whatsapp_provider_config.created', $audit->action);
        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('whatsapp_cloud', data_get($audit->after_json, 'provider'));
        $this->assertNotSame('cloud-create-secret', data_get($audit->metadata_json, 'request_payload.access_token'));
        $this->assertStringNotContainsString('cloud-create-secret', json_encode($audit->metadata_json, JSON_THROW_ON_ERROR));
    }

    public function test_creation_fails_for_unknown_provider(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-invalid-provider',
            domain: 'barbearia-provider-invalid-provider.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'), [
                'slot' => 'primary',
                'provider' => 'provider-inexistente',
            ])
            ->assertStatus(422);
    }

    public function test_creation_fails_for_incomplete_configuration(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-incomplete',
            domain: 'barbearia-provider-incomplete.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'), [
                'slot' => 'primary',
                'provider' => 'whatsapp_cloud',
                'base_url' => 'https://graph.facebook.com',
                'phone_number_id' => '123456789',
            ])
            ->assertStatus(422)
            ->assertJsonPath('normalized_error_code', 'validation_error');
    }

    public function test_creation_fails_for_unsupported_capability(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-capability',
            domain: 'barbearia-provider-capability.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'), [
                'slot' => 'primary',
                'provider' => 'gowa',
                'base_url' => 'https://api.gowa.example',
                'settings_json' => [
                    'auth_username' => 'admin',
                    'auth_password' => 'senha-super-secreta',
                ],
                'enabled_capabilities_json' => ['template'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('normalized_error_code', 'validation_error');
    }

    public function test_partial_update_preserves_existing_secret_when_not_resent(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-partial-update',
            domain: 'barbearia-provider-partial-update.test',
        );

        $configurationId = $this->createDirectConfig($tenant, $this->validWhatsappCloudPayload([
            'slot' => 'primary',
            'access_token' => 'secret-before-update',
            'phone_number_id' => '111111111',
        ]));

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->patchJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary'), [
                'phone_number_id' => '222222222',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.phone_number_id', '222222222')
            ->assertJsonPath('data.secret_presence.has_access_token', true);

        $this->assertStringNotContainsString('secret-before-update', $response->getContent());

        $this->withTenantConnection($tenant, function () use ($configurationId): void {
            $configuration = WhatsappProviderConfig::query()->findOrFail($configurationId);

            $this->assertSame('secret-before-update', $configuration->access_token);
            $this->assertSame('222222222', $configuration->phone_number_id);
        });
    }

    public function test_secret_rotation_updates_the_stored_secret_and_audits_it(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-rotation',
            domain: 'barbearia-provider-rotation.test',
        );
        $owner = $this->createTenantUser(
            tenant: $tenant,
            role: 'owner',
            email: 'owner@barbearia-provider-rotation.test',
        );

        $configurationId = $this->createDirectConfig($tenant, $this->validWhatsappCloudPayload([
            'slot' => 'primary',
            'access_token' => 'token-antigo',
        ]));

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, user: $owner))
            ->patchJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary'), [
                'access_token' => 'token-novo-rotacionado',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.secret_presence.has_access_token', true);

        $this->assertStringNotContainsString('token-novo-rotacionado', $response->getContent());

        $this->withTenantConnection($tenant, function () use ($configurationId): void {
            $configuration = WhatsappProviderConfig::query()->findOrFail($configurationId);

            $this->assertSame('token-novo-rotacionado', $configuration->access_token);
        });

        $rotatedAudit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'whatsapp_provider_config.rotated_secret')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame($owner->id, $rotatedAudit->actor_user_id);
        $this->assertSame(['access_token'], data_get($rotatedAudit->metadata_json, 'rotated_secret_fields'));
        $this->assertStringNotContainsString('token-novo-rotacionado', json_encode($rotatedAudit->metadata_json, JSON_THROW_ON_ERROR));
    }

    public function test_valid_configuration_can_be_activated(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-activate',
            domain: 'barbearia-provider-activate.test',
        );

        $configurationId = $this->createDirectConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'fake',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => false,
        ]);

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary/activate'))
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->withTenantConnection($tenant, function () use ($configurationId): void {
            $configuration = WhatsappProviderConfig::query()->findOrFail($configurationId);

            $this->assertTrue((bool) $configuration->enabled);
        });
    }

    public function test_activation_is_blocked_for_invalid_configuration(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-invalid-activation',
            domain: 'barbearia-provider-invalid-activation.test',
        );

        $configurationId = $this->createDirectConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'enabled' => false,
        ]);

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary/activate'))
            ->assertStatus(422)
            ->assertJsonPath('normalized_error_code', 'validation_error');

        $this->withTenantConnection($tenant, function () use ($configurationId): void {
            $configuration = WhatsappProviderConfig::query()->findOrFail($configurationId);

            $this->assertFalse((bool) $configuration->enabled);
        });
    }

    public function test_healthcheck_endpoint_executes_with_sanitized_response_and_audits_the_request(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-healthcheck',
            domain: 'barbearia-provider-healthcheck.test',
        );

        $this->createDirectConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'fake',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => true,
        ]);

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'owner'))
            ->postJson($this->tenantUrl($tenant, '/admin/whatsapp-providers/primary/healthcheck'));

        $response
            ->assertOk()
            ->assertJsonPath('data.provider', 'fake')
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.http_status', 200);

        $audit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'whatsapp_provider_config.healthcheck_requested')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertTrue((bool) data_get($audit->metadata_json, 'result.healthy'));
        $this->assertSame('fake', data_get($audit->metadata_json, 'result.provider'));
    }

    public function test_user_without_permission_receives_403(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-forbidden',
            domain: 'barbearia-provider-forbidden.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist'))
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-providers'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.');
    }

    public function test_existing_artisan_commands_remain_compatible(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-artisan',
            domain: 'barbearia-provider-artisan.test',
        );

        $this->artisan(sprintf(
            'tenancy:configure-whatsapp-provider %s fake --slot=primary',
            $tenant->slug,
        ))->assertExitCode(0);

        $this->artisan(sprintf(
            'tenancy:whatsapp-healthcheck %s --slot=primary',
            $tenant->slug,
        ))->assertExitCode(0);

        $this->withTenantConnection($tenant, function (): void {
            $configuration = WhatsappProviderConfig::query()
                ->where('slot', 'primary')
                ->firstOrFail();

            $this->assertSame('fake', $configuration->provider);
            $this->assertTrue((bool) $configuration->enabled);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validWhatsappCloudPayload(array $overrides = []): array
    {
        return array_merge([
            'slot' => 'primary',
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v20.0',
            'access_token' => 'cloud-create-secret',
            'phone_number_id' => '123456789',
            'verify_token' => 'verify-create-secret',
            'webhook_secret' => 'webhook-create-secret',
            'timeout_seconds' => 15,
            'retry_profile_json' => [
                'max_attempts' => 4,
                'retry_backoff_seconds' => 90,
            ],
            'enabled_capabilities_json' => ['text', 'healthcheck', 'official_templates'],
            'enabled' => true,
        ], $overrides);
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

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
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
