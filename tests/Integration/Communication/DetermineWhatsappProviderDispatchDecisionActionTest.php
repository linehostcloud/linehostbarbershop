<?php

namespace Tests\Integration\Communication;

use App\Application\Actions\Communication\DetermineWhatsappProviderDispatchDecisionAction;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class DetermineWhatsappProviderDispatchDecisionActionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_uses_primary_when_primary_health_is_healthy(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-19 10:20:00'));

        $tenant = $this->provisionTenant(
            slug: 'barbearia-dispatch-primary-healthy',
            domain: 'barbearia-dispatch-primary-healthy.test',
        );

        try {
            $this->withTenantConnection($tenant, function (): void {
                WhatsappProviderConfig::query()->create([
                    'slot' => 'primary',
                    'provider' => 'fake',
                    'enabled' => true,
                ]);

                $this->createAttempt('fake', 'succeeded', null, '2026-03-19 10:05:00');

                $decision = app(DetermineWhatsappProviderDispatchDecisionAction::class)->execute(
                    outboxEvent: OutboxEvent::make(['context_json' => []]),
                    message: Message::make(['provider' => 'fake', 'payload_json' => []]),
                    capability: 'text',
                );

                $this->assertSame('fake', $decision->resolvedProvider->configuration->provider);
                $this->assertSame('primary_default', $decision->providerDecisionSource);
                $this->assertSame('primary', $decision->dispatchVariant);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_uses_secondary_when_primary_health_is_degraded(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-19 10:20:00'));

        $tenant = $this->provisionTenant(
            slug: 'barbearia-dispatch-secondary-health',
            domain: 'barbearia-dispatch-secondary-health.test',
        );

        try {
            $this->withTenantConnection($tenant, function (): void {
                WhatsappProviderConfig::query()->create([
                    'slot' => 'primary',
                    'provider' => 'fake',
                    'enabled' => true,
                ]);
                WhatsappProviderConfig::query()->create([
                    'slot' => 'secondary',
                    'provider' => 'fake-transient-failure',
                    'enabled' => true,
                ]);

                $this->createAttempt('fake', 'failed', 'provider_unavailable', '2026-03-19 10:10:00');

                $decision = app(DetermineWhatsappProviderDispatchDecisionAction::class)->execute(
                    outboxEvent: OutboxEvent::make(['context_json' => []]),
                    message: Message::make(['provider' => 'fake', 'payload_json' => []]),
                    capability: 'text',
                );

                $this->assertSame('fake-transient-failure', $decision->resolvedProvider->configuration->provider);
                $this->assertSame('health_based_secondary', $decision->providerDecisionSource);
                $this->assertSame('health_secondary', $decision->dispatchVariant);
                $this->assertStringContainsString('Secondary selected', $decision->decisionReason);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_respects_a_previously_pinned_fallback_route(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-dispatch-fallback-pinned',
            domain: 'barbearia-dispatch-fallback-pinned.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);
            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'fake-transient-failure',
                'enabled' => true,
            ]);

            $decision = app(DetermineWhatsappProviderDispatchDecisionAction::class)->execute(
                outboxEvent: OutboxEvent::make([
                    'context_json' => [
                        'whatsapp_fallback' => [
                            'active' => true,
                            'from_provider' => 'fake',
                            'from_slot' => 'primary',
                            'to_provider' => 'fake-transient-failure',
                            'to_slot' => 'secondary',
                        ],
                    ],
                ]),
                message: Message::make(['provider' => 'fake', 'payload_json' => []]),
                capability: 'text',
            );

            $this->assertSame('fake-transient-failure', $decision->resolvedProvider->configuration->provider);
            $this->assertSame('fallback_pinned', $decision->providerDecisionSource);
            $this->assertSame('fallback', $decision->dispatchVariant);
            $this->assertSame('Fallback already active for this outbox event.', $decision->decisionReason);
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
            'retryable' => false,
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
