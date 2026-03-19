<?php

namespace Tests\Integration\Communication;

use App\Application\Actions\Communication\DetermineWhatsappFallbackDecisionAction;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class WhatsappFallbackDecisionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_allows_fallback_only_for_the_supported_retryable_error_codes(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-fallback-decision',
            domain: 'barbearia-fallback-decision.test',
        );

        $this->withTenantConnection($tenant, function (): void {
            WhatsappProviderConfig::query()->create([
                'slot' => 'primary',
                'provider' => 'fake',
                'fallback_provider' => 'fake-transient-failure',
                'enabled' => true,
                'settings_json' => [
                    'fallback' => ['enabled' => true],
                ],
            ]);

            WhatsappProviderConfig::query()->create([
                'slot' => 'secondary',
                'provider' => 'fake-transient-failure',
                'enabled' => true,
                'settings_json' => [
                    'testing' => ['fail_on_attempts' => []],
                ],
            ]);

            $decisionAction = app(DetermineWhatsappFallbackDecisionAction::class);
            $resolvedPrimary = app(TenantWhatsappProviderResolver::class)->resolveForOutbound();
            $outboxEvent = OutboxEvent::make([
                'attempt_count' => 1,
                'max_attempts' => 3,
                'context_json' => null,
            ]);

            foreach ([
                WhatsappProviderErrorCode::ProviderUnavailable,
                WhatsappProviderErrorCode::TimeoutError,
                WhatsappProviderErrorCode::RateLimit,
                WhatsappProviderErrorCode::TransientNetworkError,
            ] as $code) {
                $decision = $decisionAction->execute(
                    outboxEvent: $outboxEvent,
                    resolvedProvider: $resolvedPrimary,
                    capability: 'text',
                    error: new ProviderErrorData(
                        code: $code,
                        message: sprintf('Erro elegivel %s.', $code->value),
                        retryable: true,
                    ),
                );

                $this->assertNotNull($decision, sprintf('Era esperado fallback para %s.', $code->value));
                $this->assertSame('fake', $decision->fromProvider);
                $this->assertSame('primary', $decision->fromSlot);
                $this->assertSame('fake-transient-failure', $decision->toProvider);
                $this->assertSame('secondary', $decision->toSlot);
                $this->assertSame($code->value, $decision->triggerErrorCode);
            }

            foreach ([
                WhatsappProviderErrorCode::ValidationError,
                WhatsappProviderErrorCode::AuthenticationError,
                WhatsappProviderErrorCode::UnsupportedFeature,
                WhatsappProviderErrorCode::PermanentProviderError,
            ] as $code) {
                $decision = $decisionAction->execute(
                    outboxEvent: $outboxEvent,
                    resolvedProvider: $resolvedPrimary,
                    capability: 'text',
                    error: new ProviderErrorData(
                        code: $code,
                        message: sprintf('Erro nao elegivel %s.', $code->value),
                        retryable: false,
                    ),
                );

                $this->assertNull($decision, sprintf('Nao era esperado fallback para %s.', $code->value));
            }
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
