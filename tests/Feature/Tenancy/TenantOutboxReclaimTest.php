<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Observability\ReclaimStaleOutboxEventsAction;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantOutboxReclaimTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_reclaims_a_truly_stale_processing_event_without_dispatch_evidence(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-safe',
            domain: 'barbearia-reclaim-safe.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Reclaim Seguro', '+5511999996101');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent);
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $eventLog = EventLog::query()->findOrFail($outboxEvent->event_log_id);

            $this->assertSame('retry_scheduled', $outboxEvent->status);
            $this->assertSame(1, $outboxEvent->reclaim_count);
            $this->assertSame('stale_processing_reclaimed', $outboxEvent->last_reclaim_reason);
            $this->assertNull($outboxEvent->reserved_at);
            $this->assertNotNull($outboxEvent->last_reclaimed_at);
            $this->assertNotNull($outboxEvent->available_at);
            $this->assertSame('retry_scheduled', $eventLog->status);

            $audit = EventLog::query()
                ->where('event_name', 'outbox.event.reclaimed')
                ->where('aggregate_id', $outboxEvent->id)
                ->firstOrFail();

            $this->assertSame('reclaimed', data_get($audit->result_json, 'decision'));
            $this->assertSame($outboxEvent->id, data_get($audit->payload_json, 'outbox_event_id'));
        });
    }

    public function test_it_does_not_reclaim_a_processing_item_still_inside_the_stale_window(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-healthy',
            domain: 'barbearia-reclaim-healthy.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Ainda Saudavel', '+5511999996102');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent, secondsAgo: 60);
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('processing', $outboxEvent->status);
            $this->assertSame(0, $outboxEvent->reclaim_count);
            $this->assertSame(0, EventLog::query()->where('event_name', 'outbox.event.reclaimed')->count());
        });
    }

    public function test_it_does_not_reclaim_terminal_outbox_events(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-terminal',
            domain: 'barbearia-reclaim-terminal.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Terminal', '+5511999996103');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $outboxEvent->forceFill([
                'status' => 'processed',
                'attempt_count' => 1,
                'reserved_at' => now()->subSeconds(500),
                'processed_at' => now()->subSeconds(490),
            ])->save();
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('processed', $outboxEvent->status);
            $this->assertSame(0, $outboxEvent->reclaim_count);
            $this->assertSame(0, EventLog::query()->where('event_name', 'outbox.event.reclaimed')->count());
            $this->assertSame(0, EventLog::query()->where('event_name', 'outbox.event.reclaim.blocked')->count());
        });
    }

    public function test_it_reconciles_processing_dispatch_that_already_has_completed_evidence(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-reconcile',
            domain: 'barbearia-reclaim-reconcile.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Dispatch Ja Concluido', '+5511999996104');

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent);
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $message = Message::query()->findOrFail($messageId);
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('processed', $outboxEvent->status);
            $this->assertSame('dispatched', $message->status);
            $this->assertNotNull($message->external_message_id);
            $this->assertSame(1, IntegrationAttempt::query()->where('message_id', $messageId)->count());
            $this->assertSame(1, EventLog::query()->where('event_name', 'outbox.event.reconciled')->count());
        });
    }

    public function test_it_blocks_unsafe_dispatch_reopen_when_the_current_attempt_is_uncertain(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-unsafe',
            domain: 'barbearia-reclaim-unsafe.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Reclaim Inseguro', '+5511999996105');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent);

            IntegrationAttempt::query()->create([
                'message_id' => $messageId,
                'event_log_id' => $outboxEvent->event_log_id,
                'outbox_event_id' => $outboxEvent->id,
                'channel' => 'whatsapp',
                'provider' => 'fake',
                'operation' => 'send_message',
                'direction' => 'outbound',
                'status' => 'processing',
                'idempotency_key' => sprintf('whatsapp-dispatch:%s:%d', $outboxEvent->id, $outboxEvent->attempt_count),
                'attempt_count' => $outboxEvent->attempt_count,
                'max_attempts' => $outboxEvent->max_attempts,
                'last_attempt_at' => now()->subSeconds(400),
                'request_payload_json' => ['message_id' => $messageId],
                'sanitized_payload_json' => ['message_id' => $messageId],
            ]);
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('failed', $outboxEvent->status);
            $this->assertSame('automatic_reopen_unsafe_due_to_inflight_dispatch', $outboxEvent->last_reclaim_reason);
            $this->assertStringContainsString('risco de envio duplo', (string) $outboxEvent->failure_reason);
            $this->assertSame(0, $outboxEvent->reclaim_count);

            $audit = EventLog::query()
                ->where('event_name', 'outbox.event.reclaim.blocked')
                ->where('aggregate_id', $outboxEvent->id)
                ->firstOrFail();

            $this->assertSame('failed', data_get($audit->result_json, 'decision'));
            $this->assertFalse((bool) data_get($audit->result_json, 'blocked_by_max_reclaims'));
        });
    }

    public function test_it_marks_event_as_failed_when_max_reclaim_attempts_is_exceeded(): void
    {
        config()->set('observability.outbox.reclaim.max_attempts', 2);

        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-max',
            domain: 'barbearia-reclaim-max.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Limite Reclaim', '+5511999996106');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent, reclaimCount: 2);
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('failed', $outboxEvent->status);
            $this->assertSame('max_reclaim_attempts_exceeded', $outboxEvent->last_reclaim_reason);

            $audit = EventLog::query()
                ->where('event_name', 'outbox.event.reclaim.blocked')
                ->where('aggregate_id', $outboxEvent->id)
                ->firstOrFail();

            $this->assertTrue((bool) data_get($audit->result_json, 'blocked_by_max_reclaims'));
        });
    }

    public function test_it_reclaims_only_once_even_when_reclaim_is_triggered_twice_for_the_same_event(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-concorrente',
            domain: 'barbearia-reclaim-concorrente.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Reclaim Concorrente', '+5511999996107');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();
            $this->makeOutboxProcessingStale($outboxEvent);

            $reclaimer = app(ReclaimStaleOutboxEventsAction::class);

            $first = $reclaimer->reclaimById($outboxEvent->id);
            $second = $reclaimer->reclaimById($outboxEvent->id);

            $this->assertSame('reclaimed', $first['decision']);
            $this->assertSame('skipped', $second['decision']);

            $freshEvent = OutboxEvent::query()->findOrFail($outboxEvent->id);

            $this->assertSame('retry_scheduled', $freshEvent->status);
            $this->assertSame(1, $freshEvent->reclaim_count);
            $this->assertSame(1, EventLog::query()->where('event_name', 'outbox.event.reclaimed')->count());
        });
    }

    public function test_process_outbox_auto_runs_stale_reclaim_when_enabled(): void
    {
        config()->set('observability.outbox.reclaim.auto_run_on_process', true);

        $tenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-auto',
            domain: 'barbearia-reclaim-auto.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));

        $messageId = $this->queueWhatsappMessage($tenant, 'Cliente Reclaim Automatico', '+5511999996110');

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $this->makeOutboxProcessingStale(
                OutboxEvent::query()->where('message_id', $messageId)->firstOrFail(),
            );
        });

        $this->artisan('tenancy:process-outbox', [
            '--tenant' => [$tenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use ($messageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $messageId)->firstOrFail();

            $this->assertSame('retry_scheduled', $outboxEvent->status);
            $this->assertSame(1, $outboxEvent->reclaim_count);
            $this->assertSame(1, EventLog::query()->where('event_name', 'outbox.event.reclaimed')->count());
        });
    }

    public function test_it_reclaims_only_the_requested_tenant_via_artisan_command(): void
    {
        $firstTenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-primeira',
            domain: 'barbearia-reclaim-primeira.test',
        );
        $secondTenant = $this->provisionTenant(
            slug: 'barbearia-reclaim-segunda',
            domain: 'barbearia-reclaim-segunda.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($firstTenant, role: 'manager'));
        $firstMessageId = $this->queueWhatsappMessage($firstTenant, 'Cliente Tenant 1', '+5511999996108');

        $this->withHeaders($this->tenantAuthHeaders($secondTenant, role: 'manager'));
        $secondMessageId = $this->queueWhatsappMessage($secondTenant, 'Cliente Tenant 2', '+5511999996109');

        $this->withTenantConnection($firstTenant, function () use ($firstMessageId): void {
            $this->makeOutboxProcessingStale(
                OutboxEvent::query()->where('message_id', $firstMessageId)->firstOrFail(),
            );
        });

        $this->withTenantConnection($secondTenant, function () use ($secondMessageId): void {
            $this->makeOutboxProcessingStale(
                OutboxEvent::query()->where('message_id', $secondMessageId)->firstOrFail(),
            );
        });

        $this->artisan('tenancy:reclaim-stale-outbox', [
            '--tenant' => [$firstTenant->slug],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->withTenantConnection($firstTenant, function () use ($firstMessageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $firstMessageId)->firstOrFail();

            $this->assertSame('retry_scheduled', $outboxEvent->status);
        });

        $this->withTenantConnection($secondTenant, function () use ($secondMessageId): void {
            $outboxEvent = OutboxEvent::query()->where('message_id', $secondMessageId)->firstOrFail();

            $this->assertSame('processing', $outboxEvent->status);
        });
    }

    private function queueWhatsappMessage(Tenant $tenant, string $clientName, string $phone): string
    {
        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $clientName,
            'phone_e164' => $phone,
            'whatsapp_opt_in' => true,
        ])->assertCreated()->json('data.id');

        return $this->postJson($this->tenantUrl($tenant, '/messages/whatsapp'), [
            'client_id' => $clientId,
            'provider' => 'fake',
            'body_text' => 'Mensagem para reclaim de outbox.',
        ])->assertCreated()->json('data.id');
    }

    private function makeOutboxProcessingStale(
        OutboxEvent $outboxEvent,
        int $secondsAgo = 400,
        int $reclaimCount = 0,
    ): void {
        $outboxEvent->forceFill([
            'status' => 'processing',
            'attempt_count' => 1,
            'reclaim_count' => $reclaimCount,
            'reserved_at' => now()->subSeconds($secondsAgo),
            'processed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'last_reclaimed_at' => null,
            'last_reclaim_reason' => null,
        ])->save();

        $outboxEvent->eventLog?->forceFill([
            'status' => 'processing',
            'processed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
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
