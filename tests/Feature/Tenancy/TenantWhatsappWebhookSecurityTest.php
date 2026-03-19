<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappWebhookSecurityTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_rejects_invalid_provider_names_at_the_webhook_boundary(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-webhook-provider-invalido',
            domain: 'barbearia-webhook-provider-invalido.test',
        );

        $response = $this->postJson(sprintf('http://%s/webhooks/whatsapp/provider-inexistente', $tenant->domains()->value('domain')), [
            'event' => 'message.status',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'validation_error')
            ->assertJsonPath('boundary_rejection_code', 'provider_invalid');

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('provider_invalid', $audit->code);
        $this->assertSame('provider-inexistente', $audit->provider);
        $this->assertSame('webhook', $audit->direction);
    }

    public function test_it_rejects_webhook_with_invalid_signature_and_does_not_persist_event_log(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-webhook-signature',
            domain: 'barbearia-webhook-signature.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'whatsapp_cloud',
                'base_url' => 'https://graph.facebook.com',
                'api_version' => 'v22.0',
                'access_token' => 'token-cloud',
                'phone_number_id' => '123456',
                'webhook_secret' => 'segredo-correto',
                'enabled' => true,
            ]);
        });

        $response = $this->withHeaders([
            'X-Hub-Signature-256' => 'sha256=assinatura-invalida',
        ])->postJson(sprintf('http://%s/webhooks/whatsapp/whatsapp_cloud', $tenant->domains()->value('domain')), [
            'entry' => [],
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'webhook_signature_invalid')
            ->assertJsonPath('boundary_rejection_code', 'webhook_signature_invalid');

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, EventLog::query()->count());
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
            $this->assertSame(0, IntegrationAttempt::query()->count());
        });

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('webhook_signature_invalid', $audit->code);
        $this->assertSame('whatsapp_cloud', $audit->provider);
        $this->assertSame('webhook', $audit->direction);
        $this->assertStringContainsString('***', (string) $audit->headers_json['x-hub-signature-256']);
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
