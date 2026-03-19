<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappOperationsApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_summary_returns_consistent_whatsapp_operational_aggregates(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-summary',
            domain: 'barbearia-whatsapp-summary.test',
        );

        $messageDeliveredId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'delivered',
            'payload_json' => ['provider_slot' => 'primary'],
            'delivered_at' => '2026-03-19 08:00:00',
            'updated_at' => '2026-03-19 08:00:00',
        ]);
        $messageFailedId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'failed',
            'payload_json' => ['provider_slot' => 'primary'],
            'failed_at' => '2026-03-19 08:30:00',
            'failure_reason' => 'Falha terminal.',
            'updated_at' => '2026-03-19 08:30:00',
        ]);
        $messageDuplicateId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'duplicate_prevented',
            'deduplication_key' => 'dedup-summary-1',
            'payload_json' => [
                'provider_slot' => 'primary',
                'deduplication' => [
                    'key' => 'dedup-summary-1',
                    'duplicate_prevented' => true,
                ],
            ],
            'updated_at' => '2026-03-19 08:32:00',
        ]);
        $this->createMessage($tenant, [
            'channel' => 'email',
            'provider' => 'smtp',
            'status' => 'failed',
            'updated_at' => '2026-03-19 08:45:00',
        ]);

        $this->createOutboxEvent($tenant, [
            'message_id' => $messageDeliveredId,
            'status' => 'processed',
            'processed_at' => '2026-03-19 08:05:00',
            'updated_at' => '2026-03-19 08:05:00',
        ]);
        $this->createOutboxEvent($tenant, [
            'message_id' => $messageFailedId,
            'status' => 'failed',
            'failed_at' => '2026-03-19 08:35:00',
            'failure_reason' => 'Provider indisponivel.',
            'updated_at' => '2026-03-19 08:35:00',
        ]);

        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageDeliveredId,
            'provider' => 'fake',
            'status' => 'succeeded',
            'normalized_status' => 'delivered',
            'completed_at' => '2026-03-19 08:05:00',
            'created_at' => '2026-03-19 08:05:00',
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageFailedId,
            'provider' => 'fake',
            'status' => 'failed',
            'normalized_status' => 'failed',
            'normalized_error_code' => 'provider_unavailable',
            'failure_reason' => 'Provider indisponivel.',
            'failed_at' => '2026-03-19 08:35:00',
            'created_at' => '2026-03-19 08:35:00',
            'request_payload_json' => ['authorization' => 'Bearer summary-secret-token'],
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageDuplicateId,
            'provider' => 'fake',
            'status' => 'duplicate_prevented',
            'normalized_status' => 'duplicate_prevented',
            'created_at' => '2026-03-19 08:32:00',
            'response_payload_json' => [
                'duplicate_prevented' => true,
            ],
        ]);
        $this->createIntegrationAttempt($tenant, [
            'channel' => 'email',
            'provider' => 'smtp',
            'operation' => 'send_message',
            'direction' => 'outbound',
            'status' => 'failed',
            'normalized_error_code' => 'timeout_error',
            'created_at' => '2026-03-19 08:45:00',
        ]);

        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'code' => 'webhook_signature_invalid',
            'occurred_at' => '2026-03-19 08:40:00',
        ]);
        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'inbound',
            'endpoint' => '/api/v1/messages/whatsapp',
            'code' => 'payload_validation_failed',
            'occurred_at' => '2026-03-19 08:50:00',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageFailedId,
            'event_name' => 'whatsapp.message.duplicate_risk_detected',
            'status' => 'processed',
            'payload_json' => [
                'duplicate_risk_detected' => true,
                'risk_error_code' => 'timeout_error',
                'deduplication_key' => 'dedup-summary-1',
            ],
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'occurred_at' => '2026-03-19 08:36:00',
        ]);

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/summary', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
            ]));

        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame(3, $data['messages']['total']);
        $this->assertSame(
            ['delivered' => 1, 'duplicate_prevented' => 1, 'failed' => 1],
            $this->totalsByKey($data['messages']['status_totals'], 'status'),
        );
        $this->assertSame(
            ['failed' => 1, 'processed' => 1],
            $this->totalsByKey($data['outbox_events']['status_totals'], 'status'),
        );
        $this->assertSame(
            ['duplicate_prevented' => 1, 'failed' => 1, 'succeeded' => 1],
            $this->totalsByKey($data['integration_attempts']['status_totals'], 'status'),
        );
        $this->assertSame(
            ['provider_unavailable' => 1],
            $this->totalsByKey($data['integration_attempts']['error_code_totals'], 'error_code'),
        );
        $this->assertSame(
            ['payload_validation_failed' => 1, 'webhook_signature_invalid' => 1],
            $this->totalsByKey($data['boundary_rejections']['code_totals'], 'code'),
        );
        $this->assertSame(3, data_get($data, 'operational_cards.messages_recent_total'));
        $this->assertSame(3, data_get($data, 'operational_cards.attempts_recent_total'));
        $this->assertSame(1, data_get($data, 'operational_cards.operational_failures_total'));
        $this->assertSame(0, data_get($data, 'operational_cards.retry_scheduled_total'));
        $this->assertSame(0, data_get($data, 'operational_cards.fallback_scheduled_total'));
        $this->assertSame(0, data_get($data, 'operational_cards.fallback_executed_total'));
        $this->assertSame(1, data_get($data, 'operational_cards.duplicate_prevented_total'));
        $this->assertSame(1, data_get($data, 'operational_cards.duplicate_risk_total'));
        $this->assertSame(2, data_get($data, 'operational_cards.boundary_rejections_total'));
        $this->assertSame(0, data_get($data, 'operational_cards.pending_queue_total'));
        $this->assertSame(33.33, (float) data_get($data, 'operational_cards.operational_failure_rate'));
        $this->assertStringNotContainsString('summary-secret-token', $response->getContent());
    }

    public function test_provider_health_returns_safe_slot_metrics_and_latest_healthcheck(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-providers-health',
            domain: 'barbearia-whatsapp-providers-health.test',
        );

        $this->createProviderConfig($tenant, [
            'slot' => 'primary',
            'provider' => 'fake',
            'access_token' => 'provider-super-secret-token',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => true,
            'updated_at' => '2026-03-19 09:00:00',
        ]);
        $this->createProviderConfig($tenant, [
            'slot' => 'secondary',
            'provider' => 'whatsapp_cloud',
            'access_token' => 'cloud-hidden-token',
            'phone_number_id' => '123456789',
            'verify_token' => 'verify-hidden-token',
            'webhook_secret' => 'webhook-hidden-token',
            'enabled_capabilities_json' => ['text', 'healthcheck'],
            'enabled' => false,
            'updated_at' => '2026-03-19 08:50:00',
        ]);

        $messageSuccessId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'delivered',
            'payload_json' => ['provider_slot' => 'primary'],
        ]);
        $messageFailureId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'failed',
            'payload_json' => ['provider_slot' => 'primary'],
            'failed_at' => '2026-03-19 09:20:00',
            'updated_at' => '2026-03-19 09:20:00',
        ]);
        $messageCloudId = $this->createMessage($tenant, [
            'provider' => 'whatsapp_cloud',
            'status' => 'failed',
            'payload_json' => ['provider_slot' => 'secondary'],
            'failed_at' => '2026-03-19 09:10:00',
            'updated_at' => '2026-03-19 09:10:00',
        ]);

        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageSuccessId,
            'provider' => 'fake',
            'status' => 'succeeded',
            'normalized_status' => 'delivered',
            'created_at' => '2026-03-19 09:05:00',
            'completed_at' => '2026-03-19 09:05:00',
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageFailureId,
            'provider' => 'fake',
            'status' => 'failed',
            'normalized_status' => 'failed',
            'normalized_error_code' => 'rate_limit',
            'created_at' => '2026-03-19 09:20:00',
            'failed_at' => '2026-03-19 09:20:00',
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageFailureId,
            'provider' => 'fake',
            'status' => 'retry_scheduled',
            'normalized_status' => 'queued',
            'normalized_error_code' => 'timeout_error',
            'created_at' => '2026-03-19 09:23:00',
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageFailureId,
            'provider' => 'fake',
            'status' => 'fallback_scheduled',
            'normalized_status' => 'queued',
            'normalized_error_code' => 'provider_unavailable',
            'created_at' => '2026-03-19 09:24:00',
            'response_payload_json' => [
                'planned_fallback' => [
                    'from_provider' => 'fake',
                    'to_provider' => 'whatsapp_cloud',
                    'to_slot' => 'secondary',
                    'trigger_error_code' => 'provider_unavailable',
                ],
            ],
        ]);
        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageCloudId,
            'provider' => 'whatsapp_cloud',
            'status' => 'failed',
            'normalized_status' => 'failed',
            'normalized_error_code' => 'unsupported_feature',
            'created_at' => '2026-03-19 09:10:00',
            'failed_at' => '2026-03-19 09:10:00',
        ]);

        $this->createAuditLog($tenant, [
            'action' => 'whatsapp_provider_config.healthcheck_requested',
            'metadata_json' => [
                'provider' => 'fake',
                'slot' => 'primary',
                'result' => [
                    'provider' => 'fake',
                    'healthy' => true,
                    'http_status' => 200,
                    'latency_ms' => 143,
                    'checked_at' => '2026-03-19T09:25:00-03:00',
                ],
            ],
        ], '2026-03-19 09:25:00');
        $this->createAuditLog($tenant, [
            'action' => 'whatsapp_provider_config.activated',
            'after_json' => ['provider' => 'fake', 'slot' => 'primary'],
            'metadata_json' => ['provider' => 'fake', 'slot' => 'primary'],
        ], '2026-03-19 09:26:00');

        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'code' => 'webhook_signature_invalid',
            'occurred_at' => '2026-03-19 09:22:00',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageFailureId,
            'event_name' => 'whatsapp.message.fallback.scheduled',
            'status' => 'recorded',
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'payload_json' => [
                'fallback' => [
                    'from_provider' => 'fake',
                    'from_slot' => 'primary',
                    'to_provider' => 'whatsapp_cloud',
                    'to_slot' => 'secondary',
                    'trigger_error_code' => 'provider_unavailable',
                ],
            ],
            'occurred_at' => '2026-03-19 09:24:30',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageFailureId,
            'event_name' => 'whatsapp.message.fallback.executed',
            'status' => 'processed',
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'payload_json' => [
                'fallback' => [
                    'from_provider' => 'fake',
                    'from_slot' => 'primary',
                    'to_provider' => 'whatsapp_cloud',
                    'to_slot' => 'secondary',
                    'trigger_error_code' => 'provider_unavailable',
                ],
            ],
            'result_json' => [
                'authorization' => 'Bearer provider-feed-fallback-secret',
            ],
            'occurred_at' => '2026-03-19 09:27:00',
        ]);

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/providers', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
            ]));

        $response->assertOk()->assertJsonCount(2, 'data');

        $providers = collect($response->json('data'))->keyBy('slot');
        $primary = $providers->get('primary');
        $secondary = $providers->get('secondary');

        $this->assertTrue((bool) $primary['enabled']);
        $this->assertSame('fake', $primary['provider']);
        $this->assertSame(['text', 'healthcheck'], $primary['enabled_capabilities']);
        $this->assertSame(4, $primary['send_attempts_total']);
        $this->assertSame(1, $primary['success_attempts']);
        $this->assertSame(3, $primary['failure_attempts']);
        $this->assertSame('custom', data_get($primary, 'health_window.label'));
        $this->assertSame(1, $primary['successes_recent']);
        $this->assertSame(3, $primary['failures_recent']);
        $this->assertSame(1, $primary['retries_recent']);
        $this->assertSame(2, $primary['fallbacks_recent']);
        $this->assertSame(1, $primary['timeout_recent']);
        $this->assertSame(1, $primary['rate_limit_recent']);
        $this->assertSame(1, $primary['unavailable_recent']);
        $this->assertSame(0, $primary['transient_recent']);
        $this->assertSame(1, $primary['retry_scheduled_total']);
        $this->assertSame(1, $primary['fallback_scheduled_total']);
        $this->assertSame(1, $primary['fallback_executed_total']);
        $this->assertSame(25.0, (float) $primary['success_rate']);
        $this->assertSame(75.0, (float) $primary['failure_rate']);
        $this->assertTrue((bool) data_get($primary, 'last_healthcheck.healthy'));
        $this->assertSame('unstable', data_get($primary, 'operational_state.label'));
        $this->assertSame(
            ['provider_unavailable' => 1, 'rate_limit' => 1, 'timeout_error' => 1],
            $this->totalsByKey($primary['signal_totals'], 'code'),
        );
        $this->assertNotNull($primary['last_activity_at']);

        $this->assertFalse((bool) $secondary['enabled']);
        $this->assertSame('unsupported_feature', data_get($secondary, 'top_error_codes.0.code'));

        $this->assertStringNotContainsString('provider-super-secret-token', $response->getContent());
        $this->assertStringNotContainsString('cloud-hidden-token', $response->getContent());
        $this->assertStringNotContainsString('verify-hidden-token', $response->getContent());
        $this->assertStringNotContainsString('webhook-hidden-token', $response->getContent());
        $this->assertStringNotContainsString('provider-feed-fallback-secret', $response->getContent());
    }

    public function test_queue_lists_attention_items_applies_filters_and_does_not_leak_sensitive_data(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-queue',
            domain: 'barbearia-whatsapp-queue.test',
        );

        $messageOutboxFailedId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'queued',
            'payload_json' => ['provider_slot' => 'primary'],
        ]);
        $messageReclaimedId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'queued',
            'payload_json' => ['provider_slot' => 'primary'],
        ]);
        $messageManualReviewId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'queued',
            'payload_json' => ['provider_slot' => 'secondary'],
        ]);
        $messageTerminalFailureId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'failed',
            'payload_json' => ['provider_slot' => 'primary'],
            'failed_at' => '2026-03-19 10:20:00',
            'failure_reason' => 'Falha terminal de entrega.',
            'updated_at' => '2026-03-19 10:20:00',
        ]);
        $messageAttemptIssueId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'queued',
            'payload_json' => ['provider_slot' => 'primary'],
        ]);

        $this->createOutboxEvent($tenant, [
            'message_id' => $messageOutboxFailedId,
            'status' => 'failed',
            'failed_at' => '2026-03-19 10:00:00',
            'failure_reason' => 'Fila travada.',
            'updated_at' => '2026-03-19 10:00:00',
        ]);
        $this->createOutboxEvent($tenant, [
            'message_id' => $messageReclaimedId,
            'status' => 'retry_scheduled',
            'reclaim_count' => 1,
            'last_reclaimed_at' => '2026-03-19 10:05:00',
            'last_reclaim_reason' => 'stale_lock_recovered',
            'updated_at' => '2026-03-19 10:05:00',
        ]);
        $this->createOutboxEvent($tenant, [
            'message_id' => $messageManualReviewId,
            'status' => 'failed',
            'failed_at' => '2026-03-19 10:10:00',
            'last_reclaim_reason' => 'max_reclaim_attempts_exceeded',
            'failure_reason' => 'Revisao manual obrigatoria.',
            'updated_at' => '2026-03-19 10:10:00',
        ]);

        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageAttemptIssueId,
            'provider' => 'fake',
            'status' => 'failed',
            'normalized_status' => 'failed',
            'normalized_error_code' => 'timeout_error',
            'failure_reason' => 'Gateway expirou.',
            'failed_at' => '2026-03-19 10:25:00',
            'created_at' => '2026-03-19 10:25:00',
            'request_payload_json' => [
                'authorization' => 'Bearer queue-ultra-secret-token',
                'provider_decision_source' => 'health_based_secondary',
                'decision_reason' => 'Secondary selected due to unavailable primary.',
                'deduplication_key' => 'dedup-queue-1',
            ],
            'response_payload_json' => [
                'duplicate_risk' => [
                    'duplicate_risk_detected' => true,
                    'risk_error_code' => 'timeout_error',
                    'deduplication_key' => 'dedup-queue-1',
                ],
            ],
            'provider_error_code' => 'ETIMEOUT',
        ]);

        $queueResponse = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/queue', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
                'per_page' => 10,
            ]));

        $queueResponse->assertOk();
        $this->assertSame(5, $queueResponse->json('meta.total'));
        $this->assertSame(
            [
                'integration_attempt_issue',
                'message_terminal_failure',
                'outbox_manual_review_required',
                'outbox_reclaimed_recently',
                'outbox_failed',
            ],
            collect($queueResponse->json('data'))->pluck('attention_type')->all(),
        );

        $filteredResponse = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/queue', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
                'attention_type' => 'integration_attempt_issue',
                'provider' => 'fake',
                'error_code' => 'timeout_error',
            ]));

        $filteredResponse
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source', 'integration_attempt')
            ->assertJsonPath('data.0.error_code', 'timeout_error')
            ->assertJsonPath('data.0.decision_source', 'health_based_secondary')
            ->assertJsonPath('data.0.duplicate_risk', true)
            ->assertJsonPath('data.0.details.decision_reason', 'Secondary selected due to unavailable primary.')
            ->assertJsonPath('data.0.details.deduplication_key', 'dedup-queue-1');

        $this->assertStringNotContainsString('queue-ultra-secret-token', $filteredResponse->getContent());
    }

    public function test_boundary_rejection_summary_and_listing_return_safe_operational_views(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-boundary',
            domain: 'barbearia-whatsapp-boundary.test',
        );

        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'code' => 'webhook_signature_invalid',
            'message' => 'Assinatura invalida.',
            'request_id' => 'req-1',
            'payload_json' => ['authorization' => 'Bearer boundary-secret-token'],
            'occurred_at' => '2026-03-19 11:00:00',
        ]);
        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'code' => 'webhook_signature_invalid',
            'message' => 'Assinatura invalida novamente.',
            'request_id' => 'req-2',
            'occurred_at' => '2026-03-19 11:05:00',
        ]);
        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'inbound',
            'endpoint' => '/api/v1/messages/whatsapp',
            'code' => 'payload_validation_failed',
            'message' => 'Payload invalido.',
            'request_id' => 'req-3',
            'occurred_at' => '2026-03-19 11:10:00',
        ]);

        $summaryResponse = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/boundary-rejections/summary', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
            ]));

        $summaryResponse->assertOk();

        $data = $summaryResponse->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(
            ['payload_validation_failed' => 1, 'webhook_signature_invalid' => 2],
            $this->totalsByKey($data['code_totals'], 'code'),
        );
        $this->assertSame(
            ['/api/v1/messages/whatsapp' => 1, '/webhooks/whatsapp/fake' => 2],
            $this->totalsByKey($data['endpoint_totals'], 'endpoint'),
        );
        $this->assertSame(
            ['inbound' => 1, 'webhook' => 2],
            $this->totalsByKey($data['direction_totals'], 'direction'),
        );
        $this->assertStringNotContainsString('boundary-secret-token', $summaryResponse->getContent());

        $listingResponse = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/boundary-rejections', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
                'code' => 'webhook_signature_invalid',
                'per_page' => 1,
            ]));

        $listingResponse
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.code', 'webhook_signature_invalid');
    }

    public function test_recent_feed_consolidates_existing_trails_without_leaking_sensitive_data(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-feed',
            domain: 'barbearia-whatsapp-feed.test',
        );

        $messageEventId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'queued',
            'payload_json' => ['provider_slot' => 'primary'],
        ]);
        $messageFailureId = $this->createMessage($tenant, [
            'provider' => 'fake',
            'status' => 'failed',
            'payload_json' => ['provider_slot' => 'primary'],
            'failed_at' => '2026-03-19 12:05:00',
            'updated_at' => '2026-03-19 12:05:00',
        ]);

        $this->createAuditLog($tenant, [
            'action' => 'whatsapp_provider_config.activated',
            'after_json' => ['provider' => 'fake', 'slot' => 'primary'],
            'metadata_json' => ['provider' => 'fake', 'slot' => 'primary'],
        ], '2026-03-19 12:00:00');
        $this->createAuditLog($tenant, [
            'action' => 'whatsapp_provider_config.healthcheck_requested',
            'metadata_json' => [
                'provider' => 'fake',
                'slot' => 'primary',
                'result' => [
                    'provider' => 'fake',
                    'healthy' => false,
                    'http_status' => 503,
                    'error' => [
                        'code' => 'provider_unavailable',
                        'secret' => 'feed-healthcheck-secret',
                    ],
                ],
            ],
        ], '2026-03-19 12:01:00');

        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'outbox.event.reclaimed',
            'status' => 'processed',
            'payload_json' => ['reason' => 'stale_lock_recovered'],
            'result_json' => ['token' => 'feed-event-secret'],
            'occurred_at' => '2026-03-19 12:02:00',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'whatsapp.message.fallback.scheduled',
            'status' => 'recorded',
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'payload_json' => [
                'fallback' => [
                    'from_provider' => 'fake',
                    'from_slot' => 'primary',
                    'to_provider' => 'whatsapp_cloud',
                    'to_slot' => 'secondary',
                    'trigger_error_code' => 'provider_unavailable',
                ],
                'authorization' => 'Bearer feed-fallback-secret',
            ],
            'occurred_at' => '2026-03-19 12:02:30',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'whatsapp.message.fallback.executed',
            'status' => 'processed',
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'payload_json' => [
                'fallback' => [
                    'from_provider' => 'fake',
                    'from_slot' => 'primary',
                    'to_provider' => 'whatsapp_cloud',
                    'to_slot' => 'secondary',
                    'trigger_error_code' => 'provider_unavailable',
                ],
            ],
            'result_json' => ['secret' => 'feed-fallback-executed-secret'],
            'occurred_at' => '2026-03-19 12:02:45',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'outbox.event.reclaim.blocked',
            'status' => 'failed',
            'payload_json' => ['reason' => 'max_reclaim_attempts_exceeded'],
            'result_json' => ['secret' => 'feed-manual-review-secret'],
            'occurred_at' => '2026-03-19 12:03:00',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'whatsapp.message.duplicate_prevented',
            'status' => 'processed',
            'payload_json' => [
                'duplicate_prevented' => true,
                'deduplication_key' => 'feed-dedup-key',
                'provider_decision_source' => 'primary_default',
                'decision_reason' => 'Primary healthy.',
            ],
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'occurred_at' => '2026-03-19 12:03:20',
        ]);
        $this->createEventLog($tenant, [
            'message_id' => $messageEventId,
            'event_name' => 'whatsapp.message.duplicate_risk_detected',
            'status' => 'processed',
            'payload_json' => [
                'duplicate_risk_detected' => true,
                'risk_error_code' => 'timeout_error',
                'deduplication_key' => 'feed-dedup-key',
                'provider_decision_source' => 'primary_default',
                'decision_reason' => 'Primary healthy.',
            ],
            'context_json' => [
                'provider' => 'fake',
                'provider_slot' => 'primary',
            ],
            'occurred_at' => '2026-03-19 12:03:30',
        ]);

        $this->createBoundaryRejectionAudit($tenant, [
            'provider' => 'fake',
            'slot' => 'primary',
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'code' => 'webhook_signature_invalid',
            'message' => 'Assinatura invalida.',
            'payload_json' => ['signature' => 'feed-boundary-secret'],
            'occurred_at' => '2026-03-19 12:04:00',
        ]);

        $this->createIntegrationAttempt($tenant, [
            'message_id' => $messageFailureId,
            'provider' => 'fake',
            'status' => 'failed',
            'normalized_status' => 'failed',
            'normalized_error_code' => 'unsupported_feature',
            'retryable' => false,
            'failure_reason' => 'Feature nao suportada.',
            'failed_at' => '2026-03-19 12:05:00',
            'created_at' => '2026-03-19 12:05:00',
            'request_payload_json' => [
                'authorization' => 'Bearer feed-integration-secret',
                'provider_decision_source' => 'health_based_secondary',
                'decision_reason' => 'Secondary selected due to unavailable primary.',
                'deduplication_key' => 'feed-dedup-key',
            ],
            'response_payload_json' => [
                'duplicate_risk' => [
                    'duplicate_risk_detected' => true,
                    'risk_error_code' => 'timeout_error',
                    'deduplication_key' => 'feed-dedup-key',
                ],
            ],
        ]);

        $response = $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/feed', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
                'per_page' => 10,
            ]));

        $response->assertOk();

        $items = collect($response->json('data'));

        $this->assertSame(10, $response->json('meta.total'));
        $this->assertSame('terminal_failure', $items->first()['type']);
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'provider_config_activated'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'provider_healthcheck'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'outbox_reclaimed'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'provider_fallback_scheduled'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'provider_fallback_executed'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'duplicate_prevented'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'duplicate_risk_detected'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'manual_review_required'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['type'] === 'boundary_rejection'));
        $this->assertTrue($items->contains(
            fn (array $item): bool => $item['type'] === 'duplicate_prevented'
                && (string) data_get($item, 'details.deduplication_key') === 'feed-dedup-key'
                && (bool) data_get($item, 'details.duplicate_prevented') === true
        ));
        $this->assertTrue($items->contains(
            fn (array $item): bool => $item['type'] === 'terminal_failure'
                && (string) ($item['decision_source'] ?? '') === 'health_based_secondary'
                && (string) data_get($item, 'details.decision_reason') === 'Secondary selected due to unavailable primary.'
        ));

        $content = $response->getContent();
        $this->assertStringNotContainsString('feed-healthcheck-secret', $content);
        $this->assertStringNotContainsString('feed-event-secret', $content);
        $this->assertStringNotContainsString('feed-fallback-secret', $content);
        $this->assertStringNotContainsString('feed-fallback-executed-secret', $content);
        $this->assertStringNotContainsString('feed-manual-review-secret', $content);
        $this->assertStringNotContainsString('feed-boundary-secret', $content);
        $this->assertStringNotContainsString('feed-integration-secret', $content);
    }

    public function test_user_without_operational_permission_receives_403(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-whatsapp-ops-forbidden',
            domain: 'barbearia-whatsapp-ops-forbidden.test',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist'))
            ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/summary'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'O usuario autenticado nao possui permissao para executar esta acao neste tenant.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProviderConfig(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            $configuration = WhatsappProviderConfig::query()->create(array_merge([
                'slot' => 'primary',
                'provider' => 'fake',
                'timeout_seconds' => 10,
                'enabled_capabilities_json' => ['text', 'healthcheck'],
                'enabled' => true,
            ], $attributes));

            if (isset($attributes['updated_at'])) {
                $this->stampModelTimestamps(
                    $configuration,
                    $attributes['updated_at'],
                    $attributes['updated_at'],
                );
            }

            return $configuration->id;
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
                'thread_key' => 'thread-whatsapp-operational-test',
                'body_text' => 'Mensagem operacional de teste.',
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
                'aggregate_id' => $attributes['message_id'] ?? 'aggregate-message',
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
            $eventLogId = $attributes['event_log_id'] ?? EventLog::query()->create([
                'message_id' => $attributes['message_id'] ?? null,
                'aggregate_type' => 'message',
                'aggregate_id' => $attributes['message_id'] ?? 'aggregate-message',
                'event_name' => 'whatsapp.dispatch.requested',
                'trigger_source' => 'system',
                'status' => 'recorded',
                'payload_json' => [],
                'context_json' => [],
                'result_json' => [],
                'occurred_at' => CarbonImmutable::parse($timestamp),
            ])->id;

            $outboxEvent = OutboxEvent::query()->create(array_merge([
                'event_log_id' => $eventLogId,
                'event_name' => 'whatsapp.dispatch.requested',
                'topic' => 'whatsapp',
                'aggregate_type' => 'message',
                'aggregate_id' => $attributes['message_id'] ?? 'aggregate-message',
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBoundaryRejectionAudit(Tenant $tenant, array $attributes): string
    {
        $audit = BoundaryRejectionAudit::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'direction' => 'webhook',
            'endpoint' => '/webhooks/whatsapp/fake',
            'route_name' => 'webhooks.whatsapp.fake',
            'method' => 'POST',
            'host' => $tenant->domains()->value('domain'),
            'source_ip' => '127.0.0.1',
            'provider' => 'fake',
            'slot' => 'primary',
            'code' => 'webhook_signature_invalid',
            'message' => 'Rejeicao de boundary.',
            'http_status' => 401,
            'request_id' => 'req-boundary',
            'correlation_id' => 'corr-boundary',
            'payload_json' => [],
            'headers_json' => [],
            'context_json' => [],
            'occurred_at' => CarbonImmutable::parse('2026-03-19 11:00:00'),
        ], $attributes));

        if (isset($attributes['occurred_at']) && is_string($attributes['occurred_at'])) {
            $this->stampModelTimestamps($audit, $attributes['occurred_at'], $attributes['occurred_at']);
        }

        return $audit->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAuditLog(Tenant $tenant, array $attributes, ?string $createdAt = null): string
    {
        $audit = AuditLog::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'actor_user_id' => null,
            'auditable_type' => 'whatsapp_provider_config',
            'auditable_id' => 'provider-config',
            'action' => 'whatsapp_provider_config.activated',
            'before_json' => null,
            'after_json' => null,
            'metadata_json' => [],
        ], $attributes));

        if ($createdAt !== null) {
            $this->stampModelTimestamps($audit, $createdAt, $createdAt);
        }

        return $audit->id;
    }

    /**
     * @param  list<array<string, int|string>>  $pairs
     * @return array<string, int>
     */
    private function totalsByKey(array $pairs, string $key): array
    {
        $totals = [];

        foreach ($pairs as $pair) {
            $totals[(string) $pair[$key]] = (int) $pair['total'];
        }

        ksort($totals);

        return $totals;
    }

    private function tenantUrl(Tenant $tenant, string $path, array $query = []): string
    {
        $domain = $tenant->domains()->value('domain');
        $url = sprintf('http://%s/api/v1%s', $domain, $path);

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
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
