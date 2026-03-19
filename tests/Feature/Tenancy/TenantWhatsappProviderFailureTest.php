<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Client\Models\Client;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Carbon;
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

    public function test_it_schedules_controlled_fallback_and_dispatches_on_secondary_provider(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-fallback-safe',
            domain: 'barbearia-provider-fallback-safe.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'fake',
                'fallback_provider' => 'fake-transient-failure',
                'retry_profile_json' => [
                    'max_attempts' => 3,
                    'retry_backoff_seconds' => 2,
                ],
                'enabled' => true,
                'settings_json' => [
                    'fallback' => ['enabled' => true],
                    'testing' => [
                        'fail_on_attempts' => [1],
                        'error_code' => 'provider_unavailable',
                        'message' => 'Provider primario indisponivel.',
                    ],
                ],
            ]);

            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'fake-transient-failure',
                'enabled' => true,
                'settings_json' => [
                    'testing' => [
                        'fail_on_attempts' => [],
                    ],
                ],
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Fallback Seguro',
            'phone_e164' => '+5511999996004',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'body_text' => 'Mensagem com fallback controlado.',
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $attempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('created_at')->firstOrFail();
            $scheduledEvent = EventLog::query()->where('event_name', 'whatsapp.message.fallback.scheduled')->latest('occurred_at')->firstOrFail();

            $this->assertSame('queued', $message->status);
            $this->assertSame('retry_scheduled', $outboxEvent->status);
            $this->assertTrue((bool) data_get($outboxEvent->context_json, 'whatsapp_fallback.active'));
            $this->assertSame('fake', data_get($outboxEvent->context_json, 'whatsapp_fallback.from_provider'));
            $this->assertSame('fake-transient-failure', data_get($outboxEvent->context_json, 'whatsapp_fallback.to_provider'));
            $this->assertSame('provider_unavailable', data_get($outboxEvent->context_json, 'whatsapp_fallback.trigger_error_code'));
            $this->assertSame('fallback_scheduled', $attempt->status);
            $this->assertSame('provider_unavailable', $attempt->normalized_error_code);
            $this->assertSame('primary', data_get($attempt->request_payload_json, 'provider_slot'));
            $this->assertSame('primary', data_get($scheduledEvent->payload_json, 'fallback.from_slot'));
            $this->assertSame('secondary', data_get($scheduledEvent->payload_json, 'fallback.to_slot'));
        });

        Carbon::setTestNow(now()->addSeconds(3));

        try {
            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $latestAttempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('created_at')->firstOrFail();
            $executedEvent = EventLog::query()->where('event_name', 'whatsapp.message.fallback.executed')->latest('occurred_at')->firstOrFail();

            $this->assertSame('dispatched', $message->status);
            $this->assertSame('fake-transient-failure', $message->provider);
            $this->assertSame('secondary', data_get($message->payload_json, 'provider_slot'));
            $this->assertTrue((bool) data_get($message->payload_json, 'fallback.used'));
            $this->assertSame('fake', data_get($message->payload_json, 'fallback.from_provider'));
            $this->assertSame('processed', $outboxEvent->status);
            $this->assertSame(2, IntegrationAttempt::query()->where('message_id', $messageId)->count());
            $this->assertSame('succeeded', $latestAttempt->status);
            $this->assertSame('secondary', data_get($latestAttempt->request_payload_json, 'provider_slot'));
            $this->assertSame('fallback', data_get($latestAttempt->request_payload_json, 'dispatch_variant'));
            $this->assertSame('fake', data_get($latestAttempt->response_payload_json, 'fallback.from_provider'));
            $this->assertSame('fake-transient-failure', data_get($executedEvent->payload_json, 'provider'));
        });
    }

    public function test_it_does_not_fallback_for_terminal_provider_errors(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-no-fallback-terminal',
            domain: 'barbearia-provider-no-fallback-terminal.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'fake',
                'fallback_provider' => 'fake-transient-failure',
                'retry_profile_json' => [
                    'max_attempts' => 3,
                    'retry_backoff_seconds' => 2,
                ],
                'enabled' => true,
                'settings_json' => [
                    'fallback' => ['enabled' => true],
                    'testing' => [
                        'fail_on_attempts' => [1],
                        'error_code' => 'unsupported_feature',
                        'message' => 'Erro terminal sem fallback.',
                    ],
                ],
            ]);

            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'fake-transient-failure',
                'enabled' => true,
                'settings_json' => [
                    'testing' => [
                        'fail_on_attempts' => [],
                    ],
                ],
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Sem Fallback Terminal',
            'phone_e164' => '+5511999996005',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'body_text' => 'Mensagem terminal sem fallback.',
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $attempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('created_at')->firstOrFail();

            $this->assertSame('failed', $message->status);
            $this->assertSame('failed', $outboxEvent->status);
            $this->assertSame('failed', $attempt->status);
            $this->assertSame('unsupported_feature', $attempt->normalized_error_code);
            $this->assertNull(data_get($outboxEvent->context_json, 'whatsapp_fallback'));
            $this->assertSame(0, EventLog::query()->where('event_name', 'whatsapp.message.fallback.scheduled')->count());
            $this->assertSame(0, EventLog::query()->where('event_name', 'whatsapp.message.fallback.executed')->count());
        });
    }

    public function test_it_does_not_fallback_when_secondary_provider_cannot_handle_the_capability(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-provider-no-fallback-capability',
            domain: 'barbearia-provider-no-fallback-capability.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'fake',
                'fallback_provider' => 'evolution_api',
                'retry_profile_json' => [
                    'max_attempts' => 3,
                    'retry_backoff_seconds' => 2,
                ],
                'enabled' => true,
                'settings_json' => [
                    'fallback' => ['enabled' => true],
                    'testing' => [
                        'fail_on_attempts' => [1],
                        'error_code' => 'timeout_error',
                        'message' => 'Timeout elegivel, mas secondary incompatível.',
                    ],
                ],
            ]);

            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'evolution_api',
                'base_url' => 'https://evolution.example',
                'api_key' => 'evo-api-key',
                'instance_name' => 'barbearia-demo',
                'enabled_capabilities_json' => ['text', 'healthcheck'],
                'enabled' => true,
            ]);
        });

        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Cliente Sem Fallback Capability',
            'phone_e164' => '+5511999996006',
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'type' => 'template',
            'body_text' => 'Mensagem template para capability.',
            'payload_json' => [
                'template_name' => 'recuperacao_capability',
            ],
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $attempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('created_at')->firstOrFail();

            $this->assertSame('retry_scheduled', $outboxEvent->status);
            $this->assertSame('retry_scheduled', $attempt->status);
            $this->assertSame('timeout_error', $attempt->normalized_error_code);
            $this->assertNull(data_get($outboxEvent->context_json, 'whatsapp_fallback'));
            $this->assertSame(0, EventLog::query()->where('event_name', 'whatsapp.message.fallback.scheduled')->count());
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

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
