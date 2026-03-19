<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Observability\RecordEventLogAction;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappDispatchCapabilityGuard;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QueueWhatsappMessageAction
{
    public function __construct(
        private readonly RecordEventLogAction $recordEventLog,
        private readonly TenantWhatsappProviderResolver $providerResolver,
        private readonly WhatsappDispatchCapabilityGuard $capabilityGuard,
        private readonly GenerateWhatsappMessageDeduplicationKeyAction $generateDeduplicationKey,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): Message
    {
        $client = Client::query()->findOrFail($payload['client_id']);
        $connection = config('tenancy.tenant_connection', 'tenant');
        $resolvedProvider = $this->providerResolver->resolveForOutbound($payload['provider'] ?? null);
        $tenantId = (string) $this->tenantContext->current()?->id;
        $type = (string) ($payload['type'] ?? 'text');
        $triggerSource = is_string($payload['trigger_source'] ?? null) && $payload['trigger_source'] !== ''
            ? (string) $payload['trigger_source']
            : 'api';

        if (! in_array($type, ['text', 'template', 'media'], true)) {
            throw new RuntimeException(sprintf('Tipo de mensagem WhatsApp "%s" nao suportado.', $type));
        }

        $capability = $this->capabilityGuard->capabilityForMessageType($type);
        $this->capabilityGuard->assert(
            $resolvedProvider->configuration->provider,
            $resolvedProvider->configuration,
            $capability,
        );

        $message = DB::connection($connection)->transaction(function () use ($client, $payload, $resolvedProvider, $tenantId, $type, $triggerSource) {
            $provider = $resolvedProvider->configuration->provider;
            $retryProfile = $resolvedProvider->configuration->retryProfile();
            $deduplication = $this->generateDeduplicationKey->execute(
                tenantId: $tenantId,
                client: $client,
                payload: $payload,
                type: $type,
            );

            $message = Message::query()->create([
                'client_id' => $client->id,
                'campaign_id' => $payload['campaign_id'] ?? null,
                'appointment_id' => $payload['appointment_id'] ?? null,
                'automation_id' => $payload['automation_id'] ?? null,
                'direction' => 'outbound',
                'channel' => 'whatsapp',
                'provider' => $provider,
                'deduplication_key' => $deduplication['key'],
                'thread_key' => $payload['thread_key'] ?? $client->phone_e164 ?? $client->id,
                'type' => $type,
                'status' => 'queued',
                'body_text' => $payload['body_text'],
                'payload_json' => array_merge($payload['payload_json'] ?? [], [
                    'requested_provider' => is_string($payload['provider'] ?? null) && $payload['provider'] !== ''
                        ? (string) $payload['provider']
                        : null,
                    'provider_slot' => $resolvedProvider->configuration->slot,
                    'deduplication' => [
                        'key' => $deduplication['key'],
                        'window_started_at' => $deduplication['window_started_at'],
                        'window_ended_at' => $deduplication['window_ended_at'],
                        'window_minutes' => $deduplication['window_minutes'],
                        'duplicate_prevented' => false,
                        'duplicate_risk_detected' => false,
                    ],
                ]),
            ]);

            $this->recordEventLog->execute(
                eventName: 'whatsapp.message.queued',
                aggregateType: 'message',
                aggregateId: $message->id,
                triggerSource: $triggerSource,
                payload: [
                    'message_id' => $message->id,
                    'client_id' => $message->client_id,
                    'appointment_id' => $message->appointment_id,
                    'automation_id' => $message->automation_id,
                    'provider' => $provider,
                    'deduplication_key' => $message->deduplication_key,
                    'thread_key' => $message->thread_key,
                    'type' => $message->type,
                    'body_text' => $message->body_text,
                ],
                context: [
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'provider' => $provider,
                    'provider_slot' => $resolvedProvider->configuration->slot,
                ],
                messageId: $message->id,
                automationId: $message->automation_id,
                outboxEventName: 'whatsapp.message.dispatch.requested',
                topic: 'whatsapp.dispatch',
                maxAttempts: $retryProfile['max_attempts'],
                retryBackoffSeconds: $retryProfile['retry_backoff_seconds'],
            );

            return $message;
        });

        return $message->load(['client', 'integrationAttempts', 'outboxEvents']);
    }
}
