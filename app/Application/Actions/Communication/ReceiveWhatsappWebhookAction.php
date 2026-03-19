<?php

namespace App\Application\Actions\Communication;

use App\Application\Actions\Observability\RecordEventLogAction;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Observability\Models\EventLog;
use App\Infrastructure\Integration\Whatsapp\TenantWhatsappProviderResolver;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReceiveWhatsappWebhookAction
{
    public function __construct(
        private readonly TenantWhatsappProviderResolver $providerResolver,
        private readonly RecordEventLogAction $recordEventLog,
        private readonly WhatsappPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array{event_log_id:string,outbox_event_id:?string,duplicate:bool,provider:string,received_at:string}
     */
    public function execute(Request $request, string $provider): array
    {
        $receivedAt = CarbonImmutable::now();
        $headers = $this->extractHeaders($request);
        $rawBody = $request->getContent();
        $payload = $request->all();
        $resolved = $this->providerResolver->resolveForWebhook($provider);
        $webhook = new ReceivedWhatsappWebhookData(
            provider: $provider,
            headers: $headers,
            payload: $payload,
            rawBody: $rawBody,
            receivedAt: $receivedAt,
        );

        $resolved->provider->validateWebhookSignature($webhook, $resolved->configuration);

        $idempotencyKey = hash('sha256', $provider.'|'.($rawBody !== '' ? $rawBody : json_encode($payload)));
        $existing = EventLog::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return [
                'event_log_id' => $existing->id,
                'outbox_event_id' => $existing->outboxEvents()->value('id'),
                'duplicate' => true,
                'provider' => $provider,
                'received_at' => $receivedAt->toIso8601String(),
            ];
        }

        $retryProfile = $resolved->configuration->retryProfile();
        $eventLog = $this->recordEventLog->execute(
            eventName: 'whatsapp.webhook.received',
            aggregateType: 'whatsapp_webhook',
            aggregateId: data_get($payload, 'message.id')
                ?? data_get($payload, 'messages.0.id')
                ?? data_get($payload, 'statuses.0.id')
                ?? data_get($payload, 'entry.0.id')
                ?? (string) \Illuminate\Support\Str::ulid(),
            triggerSource: 'webhook',
            payload: $payload,
            context: [
                'provider' => $provider,
                'provider_slot' => $resolved->configuration->slot,
                'host' => $request->getHost(),
                'tenant_slug_header' => $request->header((string) config('tenancy.identification.tenant_slug_header', 'X-Tenant-Slug')),
                'headers' => $this->sanitizer->sanitizeHeaders([
                    'content-type' => $request->header('content-type'),
                    'user-agent' => $request->userAgent(),
                    'x-hub-signature-256' => $request->header('x-hub-signature-256'),
                    'x-evolution-signature' => $request->header('x-evolution-signature'),
                    'x-webhook-secret' => $request->header('x-webhook-secret'),
                    'x-request-id' => $request->header('x-request-id'),
                    'x-fb-trace-id' => $request->header('x-fb-trace-id'),
                ]),
                'raw_body_sha256' => hash('sha256', $rawBody !== '' ? $rawBody : json_encode($payload)),
            ],
            outboxEventName: 'whatsapp.webhook.process.requested',
            topic: 'whatsapp.webhook',
            idempotencyKey: $idempotencyKey,
            maxAttempts: $retryProfile['max_attempts'],
            retryBackoffSeconds: $retryProfile['retry_backoff_seconds'],
        );

        return [
            'event_log_id' => $eventLog->id,
            'outbox_event_id' => $eventLog->outboxEvents->first()?->id,
            'duplicate' => false,
            'provider' => $provider,
            'received_at' => $receivedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extractHeaders(Request $request): array
    {
        return [
            'content-type' => $request->header('content-type'),
            'user-agent' => $request->userAgent(),
            'x-hub-signature-256' => $request->header('x-hub-signature-256'),
            'x-evolution-signature' => $request->header('x-evolution-signature'),
            'x-webhook-secret' => $request->header('x-webhook-secret'),
            'x-request-id' => $request->header('x-request-id'),
            'x-fb-trace-id' => $request->header('x-fb-trace-id'),
        ];
    }
}
