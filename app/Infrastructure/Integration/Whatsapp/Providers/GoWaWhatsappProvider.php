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

class GoWaWhatsappProvider extends AbstractHttpWhatsappProvider implements WhatsappProvider
{
    public function providerName(): string
    {
        return 'gowa';
    }

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $username = $configuration->basicAuthUsername();
        $password = $configuration->basicAuthPassword();
        $path = (string) $configuration->setting('send_text_path', '/send/message');

        if ($username === null || $password === null) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::ValidationError,
                message: 'GoWA requer auth_username e auth_password em settings_json.',
                retryable: false,
            ));
        }

        $payload = [
            'phone' => $message->recipientPhoneE164,
            'message' => (string) $message->bodyText,
        ];

        return $this->dispatchHttpCall(
            configuration: $configuration,
            operation: 'send_text',
            requestPayload: $payload,
            requestCallback: function (PendingRequest $request) use ($username, $password, $path, $payload): Response {
                return $request
                    ->withBasicAuth($username, $password)
                    ->post($path, $payload);
            },
            successMapper: function (Response $response, int $latencyMs, array $sanitizedRequest): ProviderDispatchResult {
                $payload = (array) $response->json();

                return new ProviderDispatchResult(
                    provider: $this->providerName(),
                    normalizedStatus: WhatsappMessageStatus::Dispatched,
                    providerMessageId: data_get($payload, 'results.id')
                        ?? data_get($payload, 'data.id')
                        ?? data_get($payload, 'id'),
                    providerStatus: (string) (data_get($payload, 'message') ?? 'accepted'),
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
        return $this->unsupportedResult($this->providerName(), WhatsappCapability::Media->value);
    }

    public function normalizeWebhook(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): NormalizedWhatsappWebhookData
    {
        $payload = $webhook->payload;
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $statuses = [];
        $messages = [];
        $ignoredStatuses = [];

        if (in_array($event, ['message.received', 'MESSAGE_RECEIVED'], true)) {
            $providerMessageId = (string) ($data['id'] ?? $payload['id'] ?? '');
            $phone = data_get($data, 'from');
            $text = data_get($data, 'message');

            if ($providerMessageId !== '') {
                $messages[] = new InboundWhatsappMessageData(
                    provider: $this->providerName(),
                    providerMessageId: $providerMessageId,
                    threadKey: (string) ($phone ?: $providerMessageId),
                    phoneE164: is_string($phone) ? $phone : null,
                    type: 'text',
                    bodyText: is_string($text) ? $text : null,
                    occurredAt: $this->now(),
                    payload: $payload,
                );
            }
        }

        if (in_array($event, ['message.read', 'message.delivered', 'message.sent', 'message.failed'], true)) {
            $status = $this->parseDeliveryStatus($payload);

            if ($status !== null) {
                $statuses[] = $status;
            } elseif ($event !== '') {
                $ignoredStatuses[] = $event;
            }
        }

        return new NormalizedWhatsappWebhookData(
            provider: $this->providerName(),
            eventType: $event !== '' ? $event : 'generic',
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
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $providerMessageId = (string) ($data['id'] ?? $payload['id'] ?? '');

        if ($event === '' || $providerMessageId === '') {
            return null;
        }

        $normalizedStatus = match ($event) {
            'message.sent' => WhatsappMessageStatus::Sent,
            'message.delivered' => WhatsappMessageStatus::Delivered,
            'message.read' => WhatsappMessageStatus::Read,
            'message.failed' => WhatsappMessageStatus::Failed,
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
            providerStatus: $event,
            payload: $payload,
        );
    }

    public function validateWebhookSignature(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): void
    {
        $secret = $configuration->webhook_secret;

        if ($secret === null || $secret === '') {
            return;
        }

        $signature = (string) ($webhook->headers['x-webhook-secret'] ?? '');

        if ($signature === '' || ! hash_equals($secret, $signature)) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::WebhookSignatureInvalid,
                message: 'Webhook da GoWA com secret invalido.',
                retryable: false,
            ));
        }
    }

    public function healthCheck(WhatsappProviderConfig $configuration): ProviderHealthCheckResult
    {
        try {
            $username = $configuration->basicAuthUsername();
            $password = $configuration->basicAuthPassword();
            $path = (string) $configuration->setting('healthcheck_path', '/app/status');

            if ($username === null || $password === null) {
                throw new WhatsappProviderException(new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ValidationError,
                    message: 'GoWA requer auth_username e auth_password em settings_json.',
                    retryable: false,
                ));
            }

            $startedAt = microtime(true);
            $response = $this->baseRequest($configuration)
                ->withBasicAuth($username, $password)
                ->get($path);

            return new ProviderHealthCheckResult(
                healthy: $response->successful(),
                httpStatus: $response->status(),
                latencyMs: max(1, (int) round((microtime(true) - $startedAt) * 1000)),
                details: $response->successful() ? $this->sanitizer->sanitize((array) $response->json()) : [],
                error: $response->successful() ? null : new ProviderErrorData(
                    code: WhatsappProviderErrorCode::ProviderUnavailable,
                    message: 'Health check da GoWA falhou.',
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
}
