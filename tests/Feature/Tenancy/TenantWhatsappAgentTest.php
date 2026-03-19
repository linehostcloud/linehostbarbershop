<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Carbon\Carbon;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappAgentTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_agent_generates_provider_alert_when_primary_degrades(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-agent-provider', 'barbearia-agent-provider.test');

            $this->createProviderConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);

            $messageId = $this->createMessage($tenant, [
                'provider' => 'fake',
                'status' => 'failed',
                'payload_json' => ['provider_slot' => 'primary'],
                'failed_at' => '2026-03-19 09:55:00',
                'updated_at' => '2026-03-19 09:55:00',
            ]);

            $this->createIntegrationAttempt($tenant, [
                'message_id' => $messageId,
                'provider' => 'fake',
                'status' => 'failed',
                'normalized_status' => 'failed',
                'normalized_error_code' => 'rate_limit',
                'failure_reason' => 'Provider em rate limit.',
                'failed_at' => '2026-03-19 09:55:00',
                'created_at' => '2026-03-19 09:55:00',
            ]);

            $this->artisan('tenancy:run-whatsapp-agent', [
                '--tenant' => [$tenant->slug],
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function (): void {
                $insight = AgentInsight::query()
                    ->where('type', 'provider_health_alert')
                    ->sole();

                $this->assertSame('provider_health_alert', $insight->type);
                $this->assertSame('review_primary_provider', $insight->recommendation_type);
                $this->assertSame('active', $insight->status);
                $this->assertSame('fake', $insight->provider);
                $this->assertSame('primary', $insight->slot);
                $this->assertSame('high', $insight->severity);
                $this->assertSame(1, AgentRun::query()->count());
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.agent.insight.created')->count());
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.agent.run.completed')->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_generates_reactivation_opportunity_when_applicable(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');
        config()->set('communication.whatsapp.agent.reactivation_opportunity_min_candidates', 1);

        try {
            $tenant = $this->provisionTenant('barbearia-agent-reactivation', 'barbearia-agent-reactivation.test');
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext(
                tenant: $tenant,
                clientName: 'Cliente Insight Reativacao',
                clientPhone: '+5511999997410',
                marketingOptIn: true,
            );

            $this->seedCompletedVisit(
                tenant: $tenant,
                clientId: $clientId,
                professionalId: $professionalId,
                serviceId: $serviceId,
                appointmentStartsAt: '2026-01-10 09:00:00',
                orderClosedAt: '2026-01-10 10:00:00',
            );

            $this->artisan('tenancy:run-whatsapp-agent', [
                '--tenant' => [$tenant->slug],
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function (): void {
                $insight = AgentInsight::query()->where('type', 'automation_opportunity_reactivation')->sole();

                $this->assertSame('enable_automation', $insight->recommendation_type);
                $this->assertSame('manual_safe_action', $insight->execution_mode);
                $this->assertSame('active', $insight->status);
                $this->assertNotNull($insight->automation_id);
                $this->assertSame(1, (int) data_get($insight->evidence_json, 'eligible_candidates_at_least'));
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_generates_reminder_opportunity_when_applicable(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');
        config()->set('communication.whatsapp.agent.reminder_opportunity_min_candidates', 1);

        try {
            $tenant = $this->provisionTenant('barbearia-agent-reminder', 'barbearia-agent-reminder.test');
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:00:00');

            $this->artisan('tenancy:run-whatsapp-agent', [
                '--tenant' => [$tenant->slug],
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function (): void {
                $insight = AgentInsight::query()->where('type', 'automation_opportunity_reminder')->sole();

                $this->assertSame('enable_automation', $insight->recommendation_type);
                $this->assertSame('manual_safe_action', $insight->execution_mode);
                $this->assertSame('active', $insight->status);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_generates_duplicate_risk_alert_above_threshold(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-agent-duplicate-risk', 'barbearia-agent-duplicate-risk.test');

            $messageId = $this->createMessage($tenant, [
                'provider' => 'fake',
                'status' => 'failed',
                'payload_json' => ['provider_slot' => 'primary'],
                'updated_at' => '2026-03-19 09:50:00',
            ]);

            $this->createEventLog($tenant, [
                'message_id' => $messageId,
                'event_name' => 'whatsapp.message.duplicate_risk_detected',
                'payload_json' => [
                    'duplicate_risk_detected' => true,
                    'risk_error_code' => 'timeout_error',
                ],
                'context_json' => [
                    'provider' => 'fake',
                    'provider_slot' => 'primary',
                ],
                'occurred_at' => '2026-03-19 09:45:00',
            ]);
            $this->createEventLog($tenant, [
                'message_id' => $messageId,
                'event_name' => 'whatsapp.message.duplicate_risk_detected',
                'payload_json' => [
                    'duplicate_risk_detected' => true,
                    'risk_error_code' => 'transient_network_error',
                ],
                'context_json' => [
                    'provider' => 'fake',
                    'provider_slot' => 'primary',
                ],
                'occurred_at' => '2026-03-19 09:50:00',
            ]);

            $this->artisan('tenancy:run-whatsapp-agent', [
                '--tenant' => [$tenant->slug],
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function (): void {
                $insight = AgentInsight::query()->where('type', 'duplicate_risk_alert')->sole();

                $this->assertSame('review_duplicate_risk', $insight->recommendation_type);
                $this->assertSame(2, (int) data_get($insight->evidence_json, 'total'));
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_does_not_duplicate_active_equivalent_insight(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-agent-no-duplicate', 'barbearia-agent-no-duplicate.test');

            $this->createProviderConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);

            $messageId = $this->createMessage($tenant, [
                'provider' => 'fake',
                'status' => 'failed',
                'payload_json' => ['provider_slot' => 'primary'],
                'failed_at' => '2026-03-19 09:55:00',
                'updated_at' => '2026-03-19 09:55:00',
            ]);

            $this->createIntegrationAttempt($tenant, [
                'message_id' => $messageId,
                'provider' => 'fake',
                'status' => 'failed',
                'normalized_status' => 'failed',
                'normalized_error_code' => 'provider_unavailable',
                'failure_reason' => 'Provider indisponivel.',
                'failed_at' => '2026-03-19 09:55:00',
                'created_at' => '2026-03-19 09:55:00',
            ]);

            $this->artisan('tenancy:run-whatsapp-agent', ['--tenant' => [$tenant->slug]])->assertExitCode(0);
            $this->artisan('tenancy:run-whatsapp-agent', ['--tenant' => [$tenant->slug]])->assertExitCode(0);

            $this->withTenantConnection($tenant, function (): void {
                $this->assertSame(1, AgentInsight::query()->where('type', 'provider_health_alert')->count());
                $this->assertSame(2, AgentRun::query()->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_safe_recommended_action_can_be_executed_and_audited(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');
        config()->set('communication.whatsapp.agent.reminder_opportunity_min_candidates', 1);

        try {
            $tenant = $this->provisionTenant('barbearia-agent-execute', 'barbearia-agent-execute.test');
            $setupHeaders = $this->tenantAuthHeaders($tenant, role: 'manager');
            $executionHeaders = $this->tenantAuthHeaders($tenant, role: 'automation_admin');
            $this->withHeaders($setupHeaders);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:00:00');

            $this->artisan('tenancy:run-whatsapp-agent', ['--tenant' => [$tenant->slug]])->assertExitCode(0);

            $insightId = $this->withTenantConnection($tenant, function (): string {
                return AgentInsight::query()
                    ->where('type', 'automation_opportunity_reminder')
                    ->value('id');
            });

            $this->withHeaders($executionHeaders)
                ->postJson($this->tenantUrl($tenant, sprintf('/admin/whatsapp-agent/insights/%s/execute', $insightId)))
                ->assertOk()
                ->assertJsonPath('data.status', 'executed')
                ->assertJsonPath('data.execution_result.action', 'enable_automation');

            $this->withTenantConnection($tenant, function () use ($insightId): void {
                $insight = AgentInsight::query()->findOrFail($insightId);
                $automation = Automation::query()->findOrFail((string) $insight->automation_id);

                $this->assertSame('executed', $insight->status);
                $this->assertSame('active', $automation->status);
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.agent.recommendation.executed')->count());
            });

            $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_agent.recommendation_executed')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_processing_remains_tenant_scoped(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $firstTenant = $this->provisionTenant('barbearia-agent-scope-a', 'barbearia-agent-scope-a.test');
            $secondTenant = $this->provisionTenant('barbearia-agent-scope-b', 'barbearia-agent-scope-b.test');

            $this->createProviderConfig($firstTenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);

            $messageId = $this->createMessage($firstTenant, [
                'provider' => 'fake',
                'status' => 'failed',
                'payload_json' => ['provider_slot' => 'primary'],
                'failed_at' => '2026-03-19 09:55:00',
                'updated_at' => '2026-03-19 09:55:00',
            ]);

            $this->createIntegrationAttempt($firstTenant, [
                'message_id' => $messageId,
                'provider' => 'fake',
                'status' => 'failed',
                'normalized_error_code' => 'provider_unavailable',
                'failure_reason' => 'Provider indisponivel.',
                'failed_at' => '2026-03-19 09:55:00',
                'created_at' => '2026-03-19 09:55:00',
            ]);

            $this->artisan('tenancy:run-whatsapp-agent', ['--tenant' => [$firstTenant->slug]])->assertExitCode(0);

            $this->withTenantConnection($firstTenant, function (): void {
                $this->assertSame(1, AgentInsight::query()->count());
            });
            $this->withTenantConnection($secondTenant, function (): void {
                $this->assertSame(0, AgentInsight::query()->count());
                $this->assertSame(0, AgentRun::query()->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_operational_summary_feed_and_agent_endpoint_expose_agent_data(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-agent-operations', 'barbearia-agent-operations.test');
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');

            $this->createProviderConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);

            $messageId = $this->createMessage($tenant, [
                'provider' => 'fake',
                'status' => 'failed',
                'payload_json' => ['provider_slot' => 'primary'],
                'failed_at' => '2026-03-19 09:55:00',
                'updated_at' => '2026-03-19 09:55:00',
            ]);

            $this->createIntegrationAttempt($tenant, [
                'message_id' => $messageId,
                'provider' => 'fake',
                'status' => 'failed',
                'normalized_error_code' => 'timeout_error',
                'failure_reason' => 'Timeout.',
                'failed_at' => '2026-03-19 09:55:00',
                'created_at' => '2026-03-19 09:55:00',
            ]);

            $this->artisan('tenancy:run-whatsapp-agent', ['--tenant' => [$tenant->slug]])->assertExitCode(0);

            $this->withHeaders($headers)
                ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/summary', [
                    'from' => '2026-03-19T00:00:00-03:00',
                    'to' => '2026-03-20T00:00:00-03:00',
                ]))
                ->assertOk()
                ->assertJsonPath('data.operational_cards.agent_active_insights_total', 1)
                ->assertJsonPath('data.agent.active_insights_total', 1);

            $this->withHeaders($headers)
                ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/agent', [
                    'window' => '24h',
                ]))
                ->assertOk()
                ->assertJsonPath('data.summary.active_total', 1)
                ->assertJsonPath('data.insights.0.type', 'provider_health_alert')
                ->assertJsonPath('data.latest_run.status', 'completed');

            $this->withHeaders($headers)
                ->getJson($this->tenantUrl($tenant, '/operations/whatsapp/feed', [
                    'window' => '24h',
                    'type' => 'agent_insight_created',
                ]))
                ->assertOk()
                ->assertJsonPath('data.0.type', 'agent_insight_created')
                ->assertJsonPath('data.0.details.insight_type', 'provider_health_alert');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_admin_surface_requires_proper_authorization(): void
    {
        $tenant = $this->provisionTenant('barbearia-agent-authz', 'barbearia-agent-authz.test');

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'automation_admin'))
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-agent/insights'))
            ->assertOk();

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'receptionist'))
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-agent/insights'))
            ->assertForbidden();
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOperationalContext(
        Tenant $tenant,
        string $clientName = 'Cliente Agente',
        string $clientPhone = '+5511999997400',
        bool $marketingOptIn = false,
    ): array {
        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $clientName,
            'phone_e164' => $clientPhone,
            'whatsapp_opt_in' => true,
            'marketing_opt_in' => $marketingOptIn,
        ])->assertCreated()->json('data.id');

        $professionalId = $this->createProfessional($tenant, 'Profissional Agente');
        $serviceId = $this->createService($tenant, 'Servico Agente', 45, 6500);

        return [$clientId, $professionalId, $serviceId];
    }

    private function createProfessional(Tenant $tenant, string $displayName): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => $displayName,
            'role' => 'barber',
            'active' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createService(Tenant $tenant, string $name, int $durationMinutes, int $priceCents): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/services'), [
            'name' => $name,
            'category' => 'servico',
            'duration_minutes' => $durationMinutes,
            'price_cents' => $priceCents,
            'active' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createAppointment(
        Tenant $tenant,
        string $clientId,
        string $professionalId,
        string $serviceId,
        string $startsAt,
    ): string {
        return $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => $startsAt,
        ])->assertCreated()->json('data.id');
    }

    private function seedCompletedVisit(
        Tenant $tenant,
        string $clientId,
        string $professionalId,
        string $serviceId,
        string $appointmentStartsAt,
        string $orderClosedAt,
    ): void {
        $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, $appointmentStartsAt);
        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, sprintf('/orders/%s/close', $orderId)), [
            'closed_at' => $orderClosedAt,
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Servico Agente',
                    'quantity' => 1,
                    'unit_price_cents' => 6500,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'pix',
                    'amount_cents' => 6500,
                    'status' => 'paid',
                ],
            ],
        ])->assertOk();
    }

    private function createProviderConfig(Tenant $tenant, array $attributes): string
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

    private function createMessage(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return Message::query()->create(array_merge([
                'direction' => 'outbound',
                'channel' => 'whatsapp',
                'thread_key' => 'agent-thread-'.uniqid(),
                'type' => 'text',
                'status' => 'queued',
                'body_text' => 'Mensagem de teste do agente',
                'payload_json' => [],
            ], $attributes))->id;
        });
    }

    private function createIntegrationAttempt(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return IntegrationAttempt::query()->create(array_merge([
                'channel' => 'whatsapp',
                'provider' => 'fake',
                'operation' => 'send_message',
                'direction' => 'outbound',
                'status' => 'failed',
                'attempt_count' => 1,
                'max_attempts' => 3,
                'retryable' => false,
                'request_payload_json' => [],
                'response_payload_json' => [],
            ], $attributes))->id;
        });
    }

    private function createEventLog(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return EventLog::query()->create(array_merge([
                'aggregate_type' => 'message',
                'aggregate_id' => $attributes['message_id'] ?? 'agent-aggregate',
                'event_name' => 'whatsapp.message.duplicate_risk_detected',
                'trigger_source' => 'system',
                'status' => 'processed',
                'idempotency_key' => 'agent-event-'.uniqid('', true),
                'payload_json' => [],
                'context_json' => [],
                'result_json' => null,
                'occurred_at' => now(),
                'processed_at' => now(),
            ], $attributes))->id;
        });
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
