<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Observability\ProcessOutboxEventAction;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantObservabilityApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_records_domain_events_and_processes_them_through_the_outbox(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-observabilidade',
            domain: 'barbearia-observabilidade.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Evento');
        $professionalId = $this->createProfessional($tenant, 'Profissional Evento');
        $serviceId = $this->createService($tenant, 'Corte observavel', 40, 5000);

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 10:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Corte observavel',
                    'quantity' => 1,
                    'unit_price_cents' => 5000,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'pix',
                    'amount_cents' => 5000,
                ],
            ],
        ])->assertOk();

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(2, EventLog::query()->count());
            $this->assertSame(2, OutboxEvent::query()->count());
            $this->assertSame(2, EventLog::query()->where('status', 'recorded')->count());
            $this->assertSame(2, OutboxEvent::query()->where('status', 'pending')->count());
        });

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(2, EventLog::query()->where('status', 'processed')->count());
            $this->assertSame(2, OutboxEvent::query()->where('status', 'processed')->count());
        });

        $this->getJson($this->tenantUrl($tenant, '/event-logs'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'processed');
    }

    public function test_it_retries_whatsapp_dispatch_until_the_fake_gateway_succeeds(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-retry',
            domain: 'barbearia-retry.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Retry', '+5511999994001');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake-transient-failure',
            'body_text' => 'Seu horario esta reservado para hoje.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $attempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('attempt_count')->firstOrFail();

            $this->assertSame('queued', $message->status);
            $this->assertSame('retry_scheduled', $outboxEvent->status);
            $this->assertSame(1, $outboxEvent->attempt_count);
            $this->assertSame('retry_scheduled', $attempt->status);
            $this->assertSame(1, $attempt->attempt_count);
            $this->assertTrue((bool) $attempt->retryable);
            $this->assertSame('transient_network_error', $attempt->normalized_error_code);
        });

        Carbon::setTestNow(now()->addSeconds((int) config('observability.outbox.default_retry_backoff_seconds', 60)));

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
            $eventLog = EventLog::query()->where('message_id', $messageId)->firstOrFail();
            $attempt = IntegrationAttempt::query()->where('message_id', $messageId)->latest('attempt_count')->firstOrFail();

            $this->assertSame('dispatched', $message->status);
            $this->assertNotNull($message->external_message_id);
            $this->assertSame('processed', $outboxEvent->status);
            $this->assertSame('processed', $eventLog->status);
            $this->assertSame('succeeded', $attempt->status);
            $this->assertSame(2, $attempt->attempt_count);
            $this->assertSame('dispatched', $attempt->normalized_status);
            $this->assertSame(2, IntegrationAttempt::query()->where('message_id', $messageId)->count());
        });

        $this->getJson($this->tenantUrl($tenant, '/integration-attempts'))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'succeeded');
    }

    public function test_it_records_webhooks_and_updates_the_message_status_after_processing(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-webhook',
            domain: 'barbearia-webhook.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Webhook', '+5511999995001');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Lembrete do seu agendamento.',
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $externalMessageId = $this->withTenantConnection($tenant, function () use ($messageId): string {
            return (string) Message::query()->findOrFail($messageId)->external_message_id;
        });

        $webhookResponse = $this->postJson(sprintf('http://%s/webhooks/whatsapp/fake', $tenant->domains()->value('domain')), [
            'event' => 'message.status',
            'message' => [
                'id' => $externalMessageId,
                'status' => 'delivered',
            ],
        ]);

        $webhookResponse
            ->assertAccepted()
            ->assertJsonPath('provider', 'fake');

        $this->withTenantConnection($tenant, function () use ($webhookResponse): void {
            $eventLogId = $webhookResponse->json('event_log_id');
            $outboxEventId = $webhookResponse->json('outbox_event_id');

            $this->assertSame('recorded', EventLog::query()->findOrFail($eventLogId)->status);
            $this->assertSame('pending', OutboxEvent::query()->findOrFail($outboxEventId)->status);
        });

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $webhookAttempt = IntegrationAttempt::query()
                ->where('operation', 'receive_webhook')
                ->firstOrFail();

            $this->assertSame('delivered', $message->status);
            $this->assertNotNull($message->delivered_at);
            $this->assertSame('succeeded', $webhookAttempt->status);
        });
    }

    public function test_it_deduplicates_replayed_webhooks_by_payload_hash(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-webhook-dup',
            domain: 'barbearia-webhook-dup.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Webhook Duplicado', '+5511999995002');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Mensagem para testar duplicidade.',
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $externalMessageId = $this->withTenantConnection($tenant, function () use ($messageId): string {
            return (string) Message::query()->findOrFail($messageId)->external_message_id;
        });

        $payload = [
            'event' => 'message.status',
            'message' => [
                'id' => $externalMessageId,
                'status' => 'delivered',
            ],
        ];

        $first = $this->postJson(sprintf('http://%s/webhooks/whatsapp/fake', $tenant->domains()->value('domain')), $payload)
            ->assertAccepted();
        $second = $this->postJson(sprintf('http://%s/webhooks/whatsapp/fake', $tenant->domains()->value('domain')), $payload)
            ->assertAccepted();

        $this->assertFalse($first->json('duplicate'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame($first->json('event_log_id'), $second->json('event_log_id'));
        $this->assertSame($first->json('outbox_event_id'), $second->json('outbox_event_id'));

        $this->withTenantConnection($tenant, function (): void {
            $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.webhook.received')->count());
            $this->assertSame(1, OutboxEvent::query()->where('event_name', 'whatsapp.webhook.process.requested')->count());
        });
    }

    public function test_it_does_not_dispatch_twice_when_the_same_outbox_event_is_reprocessed_from_stale_snapshots(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-concorrencia-outbox',
            domain: 'barbearia-concorrencia-outbox.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Concorrencia', '+5511999995003');
        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Teste de stale snapshot no outbox.',
        ])->assertCreated()->json('data.id');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $firstSnapshot = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $secondSnapshot = OutboxEvent::query()->whereKey($firstSnapshot->id)->firstOrFail();

            $processor = app(ProcessOutboxEventAction::class);

            $processor->execute($firstSnapshot);
            $processor->execute($secondSnapshot);

            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->whereKey($firstSnapshot->id)->firstOrFail();

            $this->assertSame('processed', $outboxEvent->status);
            $this->assertSame(1, $outboxEvent->attempt_count);
            $this->assertSame('dispatched', $message->status);
            $this->assertSame(1, IntegrationAttempt::query()->where('message_id', $messageId)->count());
        });
    }

    public function test_it_does_not_regress_message_state_when_webhooks_arrive_out_of_order(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-webhook-ordem',
            domain: 'barbearia-webhook-ordem.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $clientId = $this->createClient($tenant, 'Cliente Ordem', '+5511999995004');

        $messageId = $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Mensagem para status fora de ordem.',
        ])->assertCreated()->json('data.id');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $externalMessageId = $this->withTenantConnection($tenant, function () use ($messageId): string {
            return (string) Message::query()->findOrFail($messageId)->external_message_id;
        });

        $this->postJson(sprintf('http://%s/webhooks/whatsapp/fake', $tenant->domains()->value('domain')), [
            'event' => 'message.status',
            'message' => [
                'id' => $externalMessageId,
                'status' => 'read',
            ],
        ])->assertAccepted();

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->postJson(sprintf('http://%s/webhooks/whatsapp/fake', $tenant->domains()->value('domain')), [
            'event' => 'message.status',
            'message' => [
                'id' => $externalMessageId,
                'status' => 'delivered',
            ],
        ])->assertAccepted();

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);

            $this->assertSame('read', $message->status);
            $this->assertNotNull($message->read_at);
            $this->assertNull($message->delivered_at);
        });
    }

    private function createClient(Tenant $tenant, string $name, string $phone = '+5511999991234'): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $name,
            'phone_e164' => $phone,
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createProfessional(Tenant $tenant, string $name): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => $name,
            'role' => 'barber',
            'active' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createService(Tenant $tenant, string $name, int $durationMinutes, int $priceCents): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/services'), [
            'category' => 'servico',
            'name' => $name,
            'duration_minutes' => $durationMinutes,
            'price_cents' => $priceCents,
            'active' => true,
        ])->assertCreated()->json('data.id');
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
