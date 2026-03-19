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

class WhatsappCloudProvider extends AbstractHttpWhatsappProvider implements WhatsappProvider
{
    public function providerName(): string
    {
        return 'whatsapp_cloud';
    }

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $phoneNumberId = (string) $configuration->phone_number_id;
        $apiVersion = $configuration->api_version ?: 'v22.0';

        if ($phoneNumberId === '' || $configuration->access_token === null) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'WhatsApp Cloud requer phone_number_id e access_token configurados.',
                retryable: false,
            ));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipientPhoneE164,
            'type' => 'text',
            'text' => [
                'body' => (string) $message->bodyText,
                'preview_url' => false,
            ],
        ];

        return $this->dispatchHttpCall(
            configuration: $configuration,
            operation: 'send_text',
            requestPayload: $payload,
            requestCallback: function (PendingRequest $request) use ($apiVersion, $phoneNumberId, $configuration, $payload): Response {
                return $request
                    ->withToken((string) $configuration->access_token)
                    ->post(sprintf('/%s/%s/messages', $apiVersion, $phoneNumberId), $payload);
            },
            successMapper: function (Response $response, int $latencyMs, array $sanitizedRequest): ProviderDispatchResult {
                $payload = (array) $response->json();

                return new ProviderDispatchResult(
                    provider: $this->providerName(),
                    normalizedStatus: WhatsappMessageStatus::Dispatched,
                    providerMessageId: data_get($payload, 'messages.0.id'),
                    providerStatus: 'accepted',
                    requestId: $response->header('x-request-id') ?: $response->header('x-fb-trace-id'),
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
        $phoneNumberId = (string) $configuration->phone_number_id;
        $apiVersion = $configuration->api_version ?: 'v22.0';

        if ($phoneNumberId === '' || $configuration->access_token === null || $message->templateName === null) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'WhatsApp Cloud requer phone_number_id, access_token e templateName configurados.',
                retryable: false,
            ));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $message->recipientPhoneE164,
            'type' => 'template',
            'template' => [
                'name' => $message->templateName,
                'language' => [
                    'code' => $message->templateLanguage ?: 'pt_BR',
                ],
                'components' => data_get($message->payload, 'components', []),
            ],
        ];

        return $this->dispatchHttpCall(
            configuration: $configuration,
            operation: 'send_template',
            requestPayload: $payload,
            requestCallback: function (PendingRequest $request) use ($apiVersion, $phoneNumberId, $configuration, $payload): Response {
                return $request
                    ->withToken((string) $configuration->access_token)
                    ->post(sprintf('/%s/%s/messages', $apiVersion, $phoneNumberId), $payload);
            },
            successMapper: function (Response $response, int $latencyMs, array $sanitizedRequest): ProviderDispatchResult {
                $payload = (array) $response->json();

                return new ProviderDispatchResult(
                    provider: $this->providerName(),
                    normalizedStatus: WhatsappMessageStatus::Dispatched,
                    providerMessageId: data_get($payload, 'messages.0.id'),
                    providerStatus: 'accepted',
                    requestId: $response->header('x-request-id') ?: $response->header('x-fb-trace-id'),
                    httpStatus: $response->status(),
                    latencyMs: $latencyMs,
                    occurredAt: $this->now(),
                    responsePayload: $this->sanitizer->sanitize($payload),
                    sanitizedRequestPayload: $sanitizedRequest,
                );
            },
        );
    }

    public function sendMedia(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $phoneNumberId = (string) $configuration->phone_number_id;
        $apiVersion = $configuration->api_version ?: 'v22.0';
        $mediaType = (string) data_get($message->payload, 'media_type', 'document');

        if ($phoneNumberId === '' || $configuration->access_token === null || $message->mediaUrl === null) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'WhatsApp Cloud requer phone_number_id, access_token e mediaUrl configurados.',
                retryable: false,
            ));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $message->recipientPhoneE164,
            'type' => $mediaType,
            $mediaType => array_filter([
                'link' => $message->mediaUrl,
                'caption' => $message->caption,
                'filename' => $message->mediaFilename,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        return $this->dispatchHttpCall(
            configuration: $configuration,
            operation: 'send_media',
            requestPayload: $payload,
            requestCallback: function (PendingRequest $request) use ($apiVersion, $phoneNumberId, $configuration, $payload): Response {
                return $request
                    ->withToken((string) $configuration->access_token)
                    ->post(sprintf('/%s/%s/messages', $apiVersion, $phoneNumberId), $payload);
            },
            successMapper: function (Response $response, int $latencyMs, array $sanitizedRequest): ProviderDispatchResult {
                $payload = (array) $response->json();

                return new ProviderDispatchResult(
                    provider: $this->providerName(),
                    normalizedStatus: WhatsappMessageStatus::Dispatched,
                    providerMessageId: data_get($payload, 'messages.0.id'),
                    providerStatus: 'accepted',
                    requestId: $response->header('x-request-id') ?: $response->header('x-fb-trace-id'),
                    httpStatus: $response->status(),
                    latencyMs: $latencyMs,
                    occurredAt: $this->now(),
                    responsePayload: $this->sanitizer->sanitize($payload),
                    sanitizedRequestPayload: $sanitizedRequest,
                );
            },
        );
    }

    public function normalizeWebhook(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): NormalizedWhatsappWebhookData
    {
        $payload = $webhook->payload;
        $messages = [];
        $statuses = [];
        $ignoredStatuses = [];

        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);

                foreach ((array) data_get($value, 'messages', []) as $message) {
                    $providerMessageId = (string) data_get($message, 'id');

                    if ($providerMessageId === '') {
                        continue;
                    }

                    $from = data_get($message, 'from');
                    $textBody = data_get($message, 'text.body');

                    $messages[] = new InboundWhatsappMessageData(
                        provider: $this->providerName(),
                        providerMessageId: $providerMessageId,
                        threadKey: (string) ($from ?: $providerMessageId),
                        phoneE164: is_string($from) ? $from : null,
                        type: (string) data_get($message, 'type', 'text'),
                        bodyText: is_string($textBody) ? $textBody : null,
                        occurredAt: $this->timestampToImmutable(data_get($message, 'timestamp')),
                        payload: (array) $message,
                    );
                }

                foreach ((array) data_get($value, 'statuses', []) as $statusPayload) {
                    $status = $this->parseDeliveryStatus((array) $statusPayload);

                    if ($status !== null) {
                        $statuses[] = $status;
                    } elseif (is_string(data_get($statusPayload, 'status'))) {
                        $ignoredStatuses[] = (string) data_get($statusPayload, 'status');
                    }
                }
            }
        }

        return new NormalizedWhatsappWebhookData(
            provider: $this->providerName(),
            eventType: $statuses !== [] ? 'delivery_status' : 'inbound_message',
            inboundMessages: $messages,
            statusUpdates: $statuses,
            receivedAt: $webhook->receivedAt,
            requestId: $webhook->headers['x-request-id'] ?? $webhook->headers['x-fb-trace-id'] ?? null,
            ignoredProviderStatuses: $ignoredStatuses,
            payload: $payload,
        );
    }

    public function parseDeliveryStatus(array $payload): ?ProviderStatusUpdateData
    {
        $providerMessageId = (string) ($payload['id'] ?? '');
        $providerStatus = (string) ($payload['status'] ?? '');

        if ($providerMessageId === '' || $providerStatus === '') {
            return null;
        }

        $normalizedStatus = match ($providerStatus) {
            'accepted' => WhatsappMessageStatus::Dispatched,
            'sent' => WhatsappMessageStatus::Sent,
            'delivered' => WhatsappMessageStatus::Delivered,
            'read' => WhatsappMessageStatus::Read,
            'failed' => WhatsappMessageStatus::Failed,
            default => null,
        };

        if ($normalizedStatus === null) {
            return null;
        }

        return new ProviderStatusUpdateData(
            provider: $this->providerName(),
            providerMessageId: $providerMessageId,
            normalizedStatus: $normalizedStatus,
            occurredAt: $this->timestampToImmutable($payload['timestamp'] ?? null),
            providerStatus: $providerStatus,
            error: $normalizedStatus === WhatsappMessageStatus::Failed
                ? new ProviderErrorData(
                    code: WhatsappProviderErrorCode::PermanentProviderError,
                    message: (string) data_get($payload, 'errors.0.title', 'Falha reportada pelo WhatsApp Cloud.'),
                    retryable: false,
                    providerCode: data_get($payload, 'errors.0.code'),
                )
                : null,
            phoneE164: data_get($payload, 'recipient_id'),
            payload: $payload,
        );
    }

    public function validateWebhookSignature(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): void
    {
        $secret = $configuration->webhook_secret;

        if ($secret === null || $secret === '') {
            return;
        }

        $signature = (string) ($webhook->headers['x-hub-signature-256'] ?? '');

        if (! str_starts_with($signature, 'sha256=')) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::WebhookSignatureInvalid,
                message: 'Webhook do WhatsApp Cloud sem assinatura valida.',
                retryable: false,
            ));
        }

        $expected = 'sha256='.hash_hmac('sha256', $webhook->rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::WebhookSignatureInvalid,
                message: 'Assinatura do webhook do WhatsApp Cloud invalida.',
                retryable: false,
            ));
        }
    }

    public function healthCheck(WhatsappProviderConfig $configuration): ProviderHealthCheckResult
    {
        try {
            $apiVersion = $configuration->api_version ?: 'v22.0';
            $phoneNumberId = (string) $configuration->phone_number_id;

            $startedAt = microtime(true);
            $response = $this->baseRequest($configuration)
                ->withToken((string) $configuration->access_token)
                ->get(sprintf('/%s/%s', $apiVersion, $phoneNumberId));

            return new ProviderHealthCheckResult(
                healthy: $response->successful(),
                httpStatus: $response->status(),
                latencyMs: max(1, (int) round((microtime(true) - $startedAt) * 1000)),
                details: $response->successful() ? $this->sanitizer->sanitize((array) $response->json()) : [],
                error: $response->successful() ? null : new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ProviderUnavailable,
                    message: 'Health check do WhatsApp Cloud falhou.',
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

    private function timestampToImmutable(mixed $timestamp): \Carbon\CarbonImmutable
    {
        if (is_numeric($timestamp)) {
            return \Carbon\CarbonImmutable::createFromTimestamp((int) $timestamp);
        }

        return $this->now();
    }
}
