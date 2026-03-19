<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappOperationalReadinessTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_housekeeping_reclaims_stale_outbox_and_prunes_old_operational_data(): void
    {
        config()->set('observability.whatsapp_housekeeping.automation_runs_retain_days', 7);
        config()->set('observability.whatsapp_housekeeping.agent_runs_retain_days', 7);
        config()->set('observability.whatsapp_housekeeping.agent_insights_retain_days', 7);
        config()->set('observability.whatsapp_housekeeping.event_logs_retain_days', 7);
        config()->set('observability.whatsapp_housekeeping.outbox_events_retain_days', 7);
        config()->set('observability.whatsapp_housekeeping.integration_attempts_retain_days', 7);

        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-housekeeping',
            domain: 'barbearia-whatsapp-housekeeping.test',
        );
        $automationId = $this->withTenantConnection($tenant, function (): string {
            app(\App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction::class)->execute();

            return \App\Domain\Automation\Models\Automation::query()
                ->where('channel', 'whatsapp')
                ->where('trigger_event', 'appointment_reminder')
                ->value('id');
        });

        $staleMessageId = $this->createMessage($tenant, [
            'status' => 'queued',
            'updated_at' => '2026-03-19 10:00:00',
        ]);
        $staleEventLogId = $this->createEventLog($tenant, [
            'message_id' => $staleMessageId,
            'aggregate_type' => 'message',
            'aggregate_id' => $staleMessageId,
            'event_name' => 'whatsapp.message.dispatch.requested',
            'status' => 'processing',
            'occurred_at' => '2026-03-19 10:00:00',
        ]);
        $staleOutboxId = $this->createOutboxEvent($tenant, [
            'event_log_id' => $staleEventLogId,
            'message_id' => $staleMessageId,
            'event_name' => 'whatsapp.message.dispatch.requested',
            'status' => 'processing',
            'attempt_count' => 1,
            'reserved_at' => CarbonImmutable::now()->subMinutes(10),
            'available_at' => '2026-03-19 10:00:00',
            'processed_at' => null,
            'failed_at' => null,
            'updated_at' => '2026-03-19 10:00:00',
        ]);

        $oldProcessedEventLogId = $this->createEventLog($tenant, [
            'event_name' => 'whatsapp.message.duplicate_prevented',
            'status' => 'processed',
            'occurred_at' => '2026-02-01 09:00:00',
        ]);
        $oldOutboxId = $this->createOutboxEvent($tenant, [
            'event_log_id' => $oldProcessedEventLogId,
            'status' => 'processed',
            'processed_at' => '2026-02-01 09:00:00',
            'updated_at' => '2026-02-01 09:00:00',
        ]);
        $oldAutomationRunId = $this->withTenantConnection($tenant, function () use ($automationId): string {
            return AutomationRun::query()->create([
                'automation_id' => $automationId,
                'automation_type' => 'appointment_reminder',
                'channel' => 'whatsapp',
                'status' => 'completed',
                'window_started_at' => CarbonImmutable::parse('2026-02-01 08:00:00'),
                'window_ended_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
                'candidates_found' => 1,
                'messages_queued' => 1,
                'skipped_total' => 0,
                'failed_total' => 0,
                'started_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
                'completed_at' => CarbonImmutable::parse('2026-02-01 09:01:00'),
            ])->id;
        });
        $oldAgentRunId = $this->withTenantConnection($tenant, function (): string {
            return AgentRun::query()->create([
                'channel' => 'whatsapp',
                'status' => 'completed',
                'window_started_at' => CarbonImmutable::parse('2026-02-01 08:00:00'),
                'window_ended_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
                'insights_created' => 1,
                'insights_refreshed' => 0,
                'insights_resolved' => 0,
                'insights_ignored' => 0,
                'safe_actions_executed' => 0,
                'started_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
                'completed_at' => CarbonImmutable::parse('2026-02-01 09:02:00'),
            ])->id;
        });
        $oldInsightId = $this->withTenantConnection($tenant, fn (): string => AgentInsight::query()->create([
            'agent_run_id' => $oldAgentRunId,
            'channel' => 'whatsapp',
            'insight_key' => 'old-readiness-insight',
            'type' => 'delivery_instability_alert',
            'recommendation_type' => 'review_delivery_instability',
            'status' => 'resolved',
            'severity' => 'medium',
            'priority' => 10,
            'title' => 'Insight antigo',
            'summary' => 'Insight antigo resolvido.',
            'target_type' => 'tenant',
            'target_id' => $tenant->id,
            'target_label' => $tenant->trade_name,
            'evidence_json' => [],
            'action_payload_json' => [],
            'execution_mode' => 'recommend_only',
            'first_detected_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
            'last_detected_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
            'resolved_at' => CarbonImmutable::parse('2026-02-01 09:05:00'),
        ])->id);
        $oldAttemptId = $this->createIntegrationAttempt($tenant, [
            'status' => 'succeeded',
            'normalized_status' => 'delivered',
            'retryable' => false,
            'completed_at' => '2026-02-01 09:00:00',
            'created_at' => '2026-02-01 09:00:00',
        ]);

        $this->artisan('tenancy:whatsapp-housekeeping', [
            '--tenant' => [$tenant->slug],
            '--limit' => 50,
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function () use (
            $staleOutboxId,
            $oldOutboxId,
            $oldAutomationRunId,
            $oldAgentRunId,
            $oldInsightId,
            $oldAttemptId
        ): void {
            $staleOutbox = OutboxEvent::query()->findOrFail($staleOutboxId);

            $this->assertSame('retry_scheduled', $staleOutbox->status);
            $this->assertNotNull($staleOutbox->last_reclaimed_at);
            $this->assertSame(1, $staleOutbox->reclaim_count);
            $this->assertNull(OutboxEvent::query()->find($oldOutboxId));
            $this->assertNull(AutomationRun::query()->find($oldAutomationRunId));
            $this->assertNull(AgentRun::query()->find($oldAgentRunId));
            $this->assertNull(AgentInsight::query()->find($oldInsightId));
            $this->assertNull(IntegrationAttempt::query()->find($oldAttemptId));
            $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.housekeeping.run_started')->count());

            $completed = EventLog::query()
                ->where('event_name', 'whatsapp.housekeeping.run_completed')
                ->latest('occurred_at')
                ->firstOrFail();

            $this->assertSame('completed', data_get($completed->result_json, 'status'));
            $this->assertSame(1, (int) data_get($completed->payload_json, 'reclaim.reclaimed'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($completed->payload_json, 'pruned.outbox_events'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($completed->payload_json, 'pruned.automation_runs'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($completed->payload_json, 'pruned.agent_runs'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($completed->payload_json, 'pruned.agent_insights'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($completed->payload_json, 'pruned.integration_attempts'));
        });
    }

    public function test_migrate_tenants_command_repairs_existing_tenant_schema(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-migrate-tenants',
            domain: 'barbearia-whatsapp-migrate-tenants.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            DB::connection('tenant')
                ->table('migrations')
                ->where('migration', '2026_03_19_000440_create_agent_runs_and_agent_insights_tables')
                ->delete();
            Schema::connection('tenant')->dropIfExists('agent_insights');
            Schema::connection('tenant')->dropIfExists('agent_runs');
            $this->assertFalse(Schema::connection('tenant')->hasTable('agent_runs'));
            $this->assertFalse(Schema::connection('tenant')->hasTable('agent_insights'));
        });

        $this->artisan('tenancy:migrate-tenants', [
            '--tenant' => [$tenant->slug],
        ])->assertExitCode(0);

        $this->withTenantConnection($tenant, function (): void {
            $this->assertTrue(Schema::connection('tenant')->hasTable('agent_runs'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('agent_insights'));
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMessage(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $message = Message::query()->create(array_merge([
                'channel' => 'whatsapp',
                'direction' => 'outbound',
                'provider' => 'fake',
                'type' => 'text',
                'status' => 'queued',
                'thread_key' => 'thread-whatsapp-readiness-test',
                'body_text' => 'Mensagem operacional de readiness.',
                'payload_json' => [],
            ], $attributes));

            $timestamp = $attributes['updated_at']
                ?? $attributes['failed_at']
                ?? $attributes['delivered_at']
                ?? $attributes['sent_at']
                ?? null;

            if (is_string($timestamp)) {
                $this->stampModelTimestamps($message, $timestamp, $timestamp);
            }

            return $message->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEventLog(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $eventLog = EventLog::query()->create(array_merge([
                'aggregate_type' => 'message',
                'aggregate_id' => $attributes['message_id'] ?? 'aggregate-readiness',
                'event_name' => 'outbox.event.reclaimed',
                'trigger_source' => 'system',
                'status' => 'processed',
                'payload_json' => [],
                'context_json' => [],
                'result_json' => [],
                'occurred_at' => CarbonImmutable::parse('2026-03-19 12:00:00'),
            ], $attributes));

            $timestamp = $attributes['occurred_at'] ?? null;

            if (is_string($timestamp)) {
                $this->stampModelTimestamps($eventLog, $timestamp, $timestamp);
            }

            return $eventLog->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOutboxEvent(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $timestamp = $attributes['updated_at']
                ?? $attributes['failed_at']
                ?? $attributes['last_reclaimed_at']
                ?? $attributes['processed_at']
                ?? '2026-03-19 10:00:00';

            $outboxEvent = OutboxEvent::query()->create(array_merge([
                'event_log_id' => $attributes['event_log_id'],
                'message_id' => $attributes['message_id'] ?? null,
                'event_name' => 'whatsapp.message.dispatch.requested',
                'topic' => 'whatsapp',
                'aggregate_type' => 'message',
                'aggregate_id' => $attributes['message_id'] ?? 'aggregate-readiness',
                'status' => 'pending',
                'attempt_count' => 0,
                'max_attempts' => 5,
                'retry_backoff_seconds' => 60,
                'payload_json' => [],
                'context_json' => [],
                'available_at' => CarbonImmutable::parse('2026-03-19 10:00:00'),
            ], $attributes));

            if (is_string($timestamp)) {
                $this->stampModelTimestamps($outboxEvent, $timestamp, $timestamp);
            }

            return $outboxEvent->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createIntegrationAttempt(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $attempt = IntegrationAttempt::query()->create(array_merge([
                'channel' => 'whatsapp',
                'provider' => 'fake',
                'operation' => 'send_message',
                'direction' => 'outbound',
                'status' => 'retry_scheduled',
                'retryable' => true,
                'normalized_status' => 'queued',
                'attempt_count' => 1,
                'max_attempts' => 5,
                'request_payload_json' => [],
                'response_payload_json' => [],
                'sanitized_payload_json' => [],
            ], $attributes));

            $timestamp = $attributes['created_at']
                ?? $attributes['failed_at']
                ?? $attributes['completed_at']
                ?? null;

            if (is_string($timestamp)) {
                $this->stampModelTimestamps($attempt, $timestamp, $timestamp);
            }

            return $attempt->id;
        });
    }

    private function stampModelTimestamps(Model $model, string $createdAt, string $updatedAt): void
    {
        Model::withoutTimestamps(function () use ($model, $createdAt, $updatedAt): void {
            $model->forceFill([
                'created_at' => CarbonImmutable::parse($createdAt),
                'updated_at' => CarbonImmutable::parse($updatedAt),
            ])->saveQuietly();
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
