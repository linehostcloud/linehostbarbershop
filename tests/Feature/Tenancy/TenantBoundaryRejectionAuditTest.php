<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantBoundaryRejectionAuditTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_persists_invalid_provider_rejections_with_masked_data_and_no_pipeline_artifacts(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-boundary-provider-invalido',
            domain: 'barbearia-boundary-provider-invalido.test',
        );
        $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
        $this->withHeaders($headers);

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Provider Invalido',
            'phone_e164' => '+5511999996101',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'provider-inexistente',
            'body_text' => 'Mensagem rejeitada no boundary.',
            'payload_json' => [
                'access_token' => 'token-super-secreto',
                'api_key' => 'api-key-super-secreta',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('normalized_error_code', 'validation_error')
            ->assertJsonPath('boundary_rejection_code', 'provider_invalid');

        $this->assertNoPipelineArtifacts($tenant);

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($tenant->slug, $audit->tenant_slug);
        $this->assertSame('provider_invalid', $audit->code);
        $this->assertSame('provider-inexistente', $audit->provider);
        $this->assertSame('outbound', $audit->direction);
        $this->assertSame('api/v1/messages/whatsapp', $audit->endpoint);
        $this->assertNotSame($headers['Authorization'], $audit->headers_json['authorization']);
        $this->assertStringContainsString('***', (string) $audit->headers_json['authorization']);
        $this->assertSame('toke***reto', data_get($audit->payload_json, 'payload_json.access_token'));
        $this->assertSame('api-***reta', data_get($audit->payload_json, 'payload_json.api_key'));

        $this->withHeaders($headers)
            ->getJson($this->tenantUrl($tenant, '/boundary-rejection-audits'))
            ->assertOk()
            ->assertJsonPath('data.0.code', 'provider_invalid')
            ->assertJsonPath('data.0.provider', 'provider-inexistente')
            ->assertJsonPath('data.0.headers_json.authorization', ''.$audit->headers_json['authorization'])
            ->assertJsonPath('data.0.payload_json.payload_json.access_token', 'toke***reto');
    }

    public function test_it_persists_tenant_unresolved_rejections_without_touching_any_tenant_pipeline(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-boundary-tenant-unresolved',
            domain: 'barbearia-boundary-tenant-unresolved.test',
        );

        $this->postJson('http://tenant-desconhecido.test/api/v1/messages/whatsapp', [
            'client_id' => 'cli_fake',
            'body_text' => 'Nao deveria resolver tenant.',
        ])->assertStatus(404)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('boundary_rejection_code', 'tenant_unresolved');

        $this->assertNoPipelineArtifacts($tenant);

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertNull($audit->tenant_id);
        $this->assertNull($audit->tenant_slug);
        $this->assertSame('tenant_unresolved', $audit->code);
        $this->assertSame('outbound', $audit->direction);
        $this->assertSame('api/v1/messages/whatsapp', $audit->endpoint);
        $this->assertSame('tenant-desconhecido.test', $audit->host);
    }

    public function test_it_persists_authentication_failures_on_the_whatsapp_boundary(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-boundary-auth-failed',
            domain: 'barbearia-boundary-auth-failed.test',
        );

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => 'cli_fake',
            'body_text' => 'Nao deveria autenticar.',
        ])->assertStatus(401)
            ->assertJsonPath('boundary_rejection_code', 'authentication_failed');

        $this->assertNoPipelineArtifacts($tenant);

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('authentication_failed', $audit->code);
        $this->assertSame('outbound', $audit->direction);
    }

    public function test_it_persists_authorization_failures_on_the_whatsapp_boundary(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-boundary-authorization-failed',
            domain: 'barbearia-boundary-authorization-failed.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'professional'));

        $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => 'cli_fake',
            'body_text' => 'Nao deveria autorizar.',
        ])->assertStatus(403)
            ->assertJsonPath('boundary_rejection_code', 'authorization_failed');

        $this->assertNoPipelineArtifacts($tenant);

        $audit = BoundaryRejectionAudit::query()->latest('occurred_at')->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame('authorization_failed', $audit->code);
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

    private function assertNoPipelineArtifacts(Tenant $tenant): void
    {
        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(0, Client::query()->where('full_name', 'Boundary Pipeline Ghost')->count());
            $this->assertSame(0, Message::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());
            $this->assertSame(0, IntegrationAttempt::query()->count());
        });
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
