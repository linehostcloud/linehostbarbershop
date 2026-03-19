<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappProviderFailureTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_rejects_invalid_provider_configuration_before_queueing(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-failure',
            domain: 'barbearia-provider-failure.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'whatsapp_cloud',
                'base_url' => 'https://graph.facebook.com',
                'api_version' => 'v22.0',
                'phone_number_id' => '987654321',
                'enabled' => true,
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Config Inválida',
            'phone_e164' => '+5511999996001',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'body_text' => 'Tentativa com configuracao invalida.',
        ])->assertStatus(422)
            ->assertJsonPath('boundary_rejection_code', 'provider_config_invalid')
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'validation_error');

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
            $this->assertSame(0, IntegrationAttempt::query()->count());
        });

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('provider_config_invalid', $audit->code);
        $this->assertSame('whatsapp_cloud', $audit->provider);
        $this->assertSame('primary', $audit->slot);
        $this->assertSame('outbound', $audit->direction);
    }

    public function test_it_rejects_unsupported_capability_before_queueing_and_records_boundary_audit(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-unsupported',
            domain: 'barbearia-provider-unsupported.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'evolution_api',
                'base_url' => 'https://evolution.example',
                'api_key' => 'evo-api-key',
                'instance_name' => 'barbearia-demo',
                'enabled_capabilities_json' => ['text', 'inbound_webhook', 'delivery_status', 'read_receipt', 'healthcheck'],
                'enabled' => true,
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Feature Não Suportada',
            'phone_e164' => '+5511999996002',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'type' => 'template',
            'body_text' => 'Nao deveria sair como template na Evolution.',
            'payload_json' => [
                'template_name' => 'reactivacao_padrao',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'unsupported_feature')
            ->assertJsonPath('boundary_rejection_code', 'capability_not_supported');

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
            $this->assertSame(0, IntegrationAttempt::query()->count());
        });

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('capability_not_supported', $audit->code);
        $this->assertSame('evolution_api', $audit->provider);
        $this->assertSame('primary', $audit->slot);
        $this->assertSame('outbound', $audit->direction);
    }

    public function test_it_rejects_disabled_capability_before_queueing_and_records_boundary_audit(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-capability-disabled',
            domain: 'barbearia-provider-capability-disabled.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'whatsapp_cloud',
                'base_url' => 'https://graph.facebook.com',
                'api_version' => 'v22.0',
                'access_token' => 'cloud-token',
                'phone_number_id' => '123456789',
                'enabled_capabilities_json' => ['text', 'inbound_webhook', 'delivery_status', 'read_receipt', 'healthcheck'],
                'enabled' => true,
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Capability Desabilitada',
            'phone_e164' => '+5511999996003',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'type' => 'template',
            'body_text' => 'Nao deveria sair como template sem capability habilitada.',
            'payload_json' => [
                'template_name' => 'reactivacao_padrao',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'unsupported_feature')
            ->assertJsonPath('boundary_rejection_code', 'capability_not_enabled');

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
            $this->assertSame(0, IntegrationAttempt::query()->count());
        });

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('capability_not_enabled', $audit->code);
        $this->assertSame('whatsapp_cloud', $audit->provider);
        $this->assertSame('primary', $audit->slot);
        $this->assertSame('outbound', $audit->direction);
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

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
