<?php

namespace App\Infrastructure\Integration\Whatsapp\Providers;

use App\Domain\Communication\Contracts\WhatsappProvider;
use App\Domain\Communication\Data\InboundWhatsappMessageData;
use App\Domain\Communication\Data\NormalizedWhatsappWebhookData;
use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ProviderDispatchResult;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Data\ProviderHealthCheckResult;
use App\Domain\Communication\Data\ProviderStatusUpdateData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Enums\WhatsappCapability;
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class EvolutionApiWhatsappProvider extends AbstractHttpWhatsappProvider implements WhatsappProvider
{
    public function providerName(): string
    {
        return 'evolution_api';
    }

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $instanceName = (string) $configuration->instance_name;
        $apiKey = (string) $configuration->api_key;

        if ($instanceName === '' || $apiKey === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'Evolution API requer instance_name e api_key configurados.',
                retryable: false,
            ));
        }

        $payload = [
            'number' => $message->recipientPhoneE164,
            'options' => [
                'delay' => (int) data_get($message->payload, 'delay', 0),
                'presence' => data_get($message->payload, 'presence', 'composing'),
            ],
            'textMessage' => [
                'text' => (string) $message->bodyText,
            ],
        ];

        return $this->dispatchHttpCall(
            configuration: $configuration,
            operation: 'send_text',
            requestPayload: $payload,
            requestCallback: function (PendingRequest $request) use ($instanceName, $apiKey, $payload): Response {
                return $request
                    ->withHeaders(['apikey' => $apiKey])
                    ->post(sprintf('/message/sendText/%s', $instanceName), $payload);
            },
            successMapper: function (Response $response, int $latencyMs, array $sanitizedRequest): ProviderDispatchResult {
                $payload = (array) $response->json();

                return new ProviderDispatchResult(
                    provider: $this->providerName(),
                    normalizedStatus: WhatsappMessageStatus::Dispatched,
                    providerMessageId: data_get($payload, 'key.id') ?? data_get($payload, 'message.key.id'),
                    providerStatus: (string) (data_get($payload, 'status') ?? 'accepted'),
                    requestId: $response->header('x-request-id'),
                    httpStatus: $response->status(),
                    latencyMs: $latencyMs,
                    occurredAt: $this->now(),
                    responsePayload: $this->sanitizer->sanitize($payload),
                    sanitizedRequestPayload: $sanitizedRequest,
                );
            },
        );
    }

    public function sendTemplate(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        return $this->unsupportedResult($this->providerName(), WhatsappCapability::Template->value);
    }

    public function sendMedia(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        if ($message->mediaUrl === null) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'Evolution API requer mediaUrl para envio de midia.',
                retryable: false,
            ));
        }

        return $this->unsupportedResult($this->providerName(), WhatsappCapability::Media->value);
    }

    public function normalizeWebhook(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): NormalizedWhatsappWebhookData
    {
        $payload = $webhook->payload;
        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'generic');
        $data = (array) ($payload['data'] ?? []);
        $messages = [];
        $statuses = [];
        $ignoredStatuses = [];

        if ($eventType === 'messages.upsert' || $eventType === 'MESSAGES_UPSERT') {
            $messagePayload = (array) ($data['message'] ?? []);
            $key = (array) ($data['key'] ?? $messagePayload['key'] ?? []);
            $messageValue = (array) ($messagePayload['message'] ?? $messagePayload);
            $text = data_get($messageValue, 'conversation')
                ?? data_get($messageValue, 'extendedTextMessage.text');

            if (is_string($text) && $text !== '') {
                $remoteJid = (string) ($key['remoteJid'] ?? '');
                $phone = $this->normalizeRemoteJidToPhone($remoteJid);

                $messages[] = new InboundWhatsappMessageData(
                    provider: $this->providerName(),
                    providerMessageId: (string) ($key['id'] ?? ''),
                    threadKey: $remoteJid !== '' ? $remoteJid : (string) ($key['id'] ?? ''),
                    phoneE164: $phone,
                    type: 'text',
                    bodyText: $text,
                    occurredAt: $this->timestampToImmutable($messagePayload['messageTimestamp'] ?? null),
                    payload: $payload,
                );
            }
        }

        if ($eventType === 'messages.update' || $eventType === 'MESSAGES_UPDATE') {
            $status = $this->parseDeliveryStatus($payload);

            if ($status !== null) {
                $statuses[] = $status;
            } elseif (is_string($data['status'] ?? $payload['status'] ?? null)) {
                $ignoredStatuses[] = (string) ($data['status'] ?? $payload['status']);
            }
        }

        return new NormalizedWhatsappWebhookData(
            provider: $this->providerName(),
            eventType: $eventType,
            inboundMessages: $messages,
            statusUpdates: $statuses,
            receivedAt: $webhook->receivedAt,
            requestId: $webhook->headers['x-request-id'] ?? null,
            ignoredProviderStatuses: $ignoredStatuses,
            payload: $payload,
        );
    }

    public function parseDeliveryStatus(array $payload): ?ProviderStatusUpdateData
    {
        $data = (array) ($payload['data'] ?? []);
        $key = (array) ($data['key'] ?? $data);
        $providerMessageId = (string) ($key['id'] ?? '');
        $providerStatus = (string) ($data['status'] ?? $payload['status'] ?? '');

        if ($providerMessageId === '' || $providerStatus === '') {
            return null;
        }

        $normalizedStatus = match (mb_strtolower($providerStatus)) {
            'server_ack', 'accepted' => WhatsappMessageStatus::Dispatched,
            'delivery_ack', 'sent' => WhatsappMessageStatus::Sent,
            'delivered' => WhatsappMessageStatus::Delivered,
            'read', 'played' => WhatsappMessageStatus::Read,
            'error', 'failed' => WhatsappMessageStatus::Failed,
            default => null,
        };

        if ($normalizedStatus === null) {
            return null;
        }

        return new ProviderStatusUpdateData(
            provider: $this->providerName(),
            providerMessageId: $providerMessageId,
            normalizedStatus: $normalizedStatus,
            occurredAt: $this->now(),
            providerStatus: $providerStatus,
            payload: $payload,
        );
    }

    public function validateWebhookSignature(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): void
    {
        $secret = $configuration->webhook_secret;

        if ($secret === null || $secret === '') {
            return;
        }

        $signature = (string) ($webhook->headers['x-evolution-signature'] ?? '');

        if ($signature === '') {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::WebhookSignatureInvalid,
                message: 'Webhook da Evolution API sem assinatura.',
                retryable: false,
            ));
        }

        $expected = hash_hmac('sha256', $webhook->rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::WebhookSignatureInvalid,
                message: 'Assinatura do webhook da Evolution API invalida.',
                retryable: false,
            ));
        }
    }

    public function healthCheck(WhatsappProviderConfig $configuration): ProviderHealthCheckResult
    {
        try {
            $startedAt = microtime(true);
            $response = $this->baseRequest($configuration)
                ->withHeaders(['apikey' => (string) $configuration->api_key])
                ->get('/instance/fetchInstances');

            return new ProviderHealthCheckResult(
                healthy: $response->successful(),
                httpStatus: $response->status(),
                latencyMs: max(1, (int) round((microtime(true) - $startedAt) * 1000)),
                details: $response->successful() ? $this->sanitizer->sanitize((array) $response->json()) : [],
                error: $response->successful() ? null : new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ProviderUnavailable,
                    message: 'Health check da Evolution API falhou.',
                    retryable: true,
                    httpStatus: $response->status(),
                ),
            );
        } catch (\Throwable $throwable) {
            return $this->healthcheckFailure($throwable);
        }
    }

    public function supports(string $feature): bool
    {
        return $this->supportsCapability($this->providerName(), $feature);
    }

    private function normalizeRemoteJidToPhone(string $remoteJid): ?string
    {
        $number = preg_replace('/@.+$/', '', $remoteJid);

        return is_string($number) && $number !== '' ? '+'.$number : null;
    }

    private function timestampToImmutable(mixed $timestamp): \Carbon\CarbonImmutable
    {
        if (is_numeric($timestamp)) {
            return \Carbon\CarbonImmutable::createFromTimestamp((int) $timestamp);
        }

        return $this->now();
    }
}
