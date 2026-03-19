<?php

namespace Tests\Integration\Communication;

use App\Application\Actions\Communication\CalculateWhatsappProviderHealthAction;
use App\Application\DTOs\OperationalWindow;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class CalculateWhatsappProviderHealthActionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_classifies_provider_health_states_from_recent_operational_signals(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-health-action',
            domain: 'barbearia-health-action.test',
        );

        $window = new OperationalWindow(
            label: '30m',
            startedAt: CarbonImmutable::parse('2026-03-19 10:00:00'),
            endedAt: CarbonImmutable::parse('2026-03-19 10:30:00'),
        );

        $this->withTenantConnection($tenant, function () use ($window): void {
            $healthy = WhatsappProviderConfig::make([
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);
            $degraded = WhatsappProviderConfig::make([
                'slot' => 'secondary',
                'provider' => 'gowa',
                'enabled' => true,
            ]);
            $unstable = WhatsappProviderConfig::make([
                'slot' => 'secondary',
                'provider' => 'fake-transient-failure',
                'enabled' => true,
            ]);
            $unavailable = WhatsappProviderConfig::make([
                'slot' => 'secondary',
                'provider' => 'evolution_api',
                'enabled' => true,
            ]);

            $this->createAttempt('fake', 'succeeded', null, '2026-03-19 10:05:00');
            $this->createAttempt('gowa', 'succeeded', null, '2026-03-19 10:08:00');
            $this->createAttempt('gowa', 'succeeded', null, '2026-03-19 10:09:00');
            $this->createAttempt('gowa', 'failed', 'rate_limit', '2026-03-19 10:10:00');
            $this->createAttempt('fake-transient-failure', 'succeeded', null, '2026-03-19 10:11:00');
            $this->createAttempt('fake-transient-failure', 'retry_scheduled', 'timeout_error', '2026-03-19 10:12:00');
            $this->createAttempt('fake-transient-failure', 'fallback_scheduled', 'provider_unavailable', '2026-03-19 10:13:00');
            $this->createAttempt('evolution_api', 'failed', 'provider_unavailable', '2026-03-19 10:15:00');

            EventLog::query()->create([
                'aggregate_type' => 'message',
                'aggregate_id' => 'msg-health-1',
                'event_name' => 'whatsapp.message.fallback.scheduled',
                'trigger_source' => 'system',
                'status' => 'processed',
                'payload_json' => [
                    'fallback' => [
                        'from_provider' => 'fake-transient-failure',
                        'to_provider' => 'whatsapp_cloud',
                    ],
                ],
                'context_json' => [
                    'provider' => 'fake-transient-failure',
                    'provider_slot' => 'secondary',
                ],
                'result_json' => [],
                'occurred_at' => CarbonImmutable::parse('2026-03-19 10:13:30'),
            ]);

            EventLog::query()->create([
                'aggregate_type' => 'message',
                'aggregate_id' => 'msg-health-1',
                'event_name' => 'whatsapp.message.fallback.executed',
                'trigger_source' => 'system',
                'status' => 'processed',
                'payload_json' => [
                    'fallback' => [
                        'from_provider' => 'fake-transient-failure',
                        'to_provider' => 'whatsapp_cloud',
                    ],
                ],
                'context_json' => [
                    'provider' => 'fake-transient-failure',
                    'provider_slot' => 'secondary',
                ],
                'result_json' => [],
                'occurred_at' => CarbonImmutable::parse('2026-03-19 10:14:00'),
            ]);

            $action = app(CalculateWhatsappProviderHealthAction::class);

            $healthySnapshot = $action->execute($healthy, $window);
            $degradedSnapshot = $action->execute($degraded, $window);
            $unstableSnapshot = $action->execute($unstable, $window);
            $unavailableSnapshot = $action->execute($unavailable, $window);

            $this->assertSame('healthy', $healthySnapshot->stateLabel);
            $this->assertSame(1, $healthySnapshot->successesRecent);

            $this->assertSame('degraded', $degradedSnapshot->stateLabel);
            $this->assertSame(2, $degradedSnapshot->successesRecent);
            $this->assertSame(1, $degradedSnapshot->failuresRecent);
            $this->assertSame(1, $degradedSnapshot->rateLimitRecent);

            $this->assertSame('unstable', $unstableSnapshot->stateLabel);
            $this->assertSame(1, $unstableSnapshot->retriesRecent);
            $this->assertSame(1, $unstableSnapshot->fallbackScheduledTotal);
            $this->assertSame(1, $unstableSnapshot->fallbackExecutedTotal);
            $this->assertSame(1, $unstableSnapshot->timeoutRecent);

            $this->assertSame('unavailable', $unavailableSnapshot->stateLabel);
            $this->assertSame(1, $unavailableSnapshot->unavailableRecent);
            $this->assertSame(0, $unavailableSnapshot->successesRecent);
        });
    }

    private function createAttempt(string $provider, string $status, ?string $errorCode, string $createdAt): void
    {
        $attempt = IntegrationAttempt::query()->create([
            'channel' => 'whatsapp',
            'provider' => $provider,
            'operation' => 'send_message',
            'direction' => 'outbound',
            'status' => $status,
            'retryable' => in_array($status, ['retry_scheduled', 'fallback_scheduled'], true),
            'normalized_status' => $status === 'succeeded' ? 'dispatched' : 'failed',
            'normalized_error_code' => $errorCode,
            'request_payload_json' => [],
            'response_payload_json' => [],
            'sanitized_payload_json' => [],
            'attempt_count' => 1,
            'max_attempts' => 3,
        ]);

        $this->stampModelTimestamps($attempt, $createdAt, $createdAt);
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
