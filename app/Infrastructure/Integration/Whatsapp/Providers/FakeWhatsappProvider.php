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
use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderCapabilityMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class FakeWhatsappProvider implements WhatsappProvider
{
    public function __construct(
        private readonly WhatsappProviderCapabilityMatrix $capabilityMatrix,
        private readonly string $name = 'fake',
        private readonly bool $failOnFirstAttempt = false,
    ) {
    }

    public function providerName(): string
    {
        return $this->name;
    }

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $attemptNumber = (int) data_get($message->payload, 'attempt_number', 1);

        if ($error = $this->simulatedFailure($configuration, $attemptNumber)) {
            throw new WhatsappProviderException($error);
        }

        return new ProviderDispatchResult(
            provider: $this->providerName(),
            normalizedStatus: WhatsappMessageStatus::Dispatched,
            providerMessageId: sprintf('%s-%s', $this->providerName(), Str::lower((string) Str::ulid())),
            providerStatus: 'accepted',
            requestId: (string) Str::uuid(),
            httpStatus: 202,
            latencyMs: 1,
            occurredAt: CarbonImmutable::now(),
            responsePayload: [
                'accepted' => true,
                'provider' => $this->providerName(),
            ],
            sanitizedRequestPayload: [
                'to' => $message->recipientPhoneE164,
                'type' => $message->type,
            ],
        );
    }

    public function sendTemplate(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        return $this->sendText($message, $configuration);
    }

    public function sendMedia(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        return $this->sendText($message, $configuration);
    }

    public function normalizeWebhook(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): NormalizedWhatsappWebhookData
    {
        $payload = $webhook->payload;
        $status = $this->parseDeliveryStatus($payload);
        $messages = [];

        $bodyText = data_get($payload, 'message.text')
            ?? data_get($payload, 'message.body')
            ?? data_get($payload, 'text.body')
            ?? data_get($payload, 'body');

        if (is_string($bodyText) && $bodyText !== '') {
            $providerMessageId = (string) (
                data_get($payload, 'message.id')
                ?? data_get($payload, 'external_message_id')
                ?? Str::lower((string) Str::ulid())
            );

            $phone = data_get($payload, 'message.from')
                ?? data_get($payload, 'from');

            $messages[] = new InboundWhatsappMessageData(
                provider: $this->providerName(),
                providerMessageId: $providerMessageId,
                threadKey: (string) (data_get($payload, 'thread_key') ?? $phone ?? $providerMessageId),
                phoneE164: is_string($phone) ? $phone : null,
                type: (string) (data_get($payload, 'message.type') ?? 'text'),
                bodyText: $bodyText,
                occurredAt: CarbonImmutable::now(),
                payload: $payload,
            );
        }

        return new NormalizedWhatsappWebhookData(
            provider: $this->providerName(),
            eventType: (string) (data_get($payload, 'event') ?? data_get($payload, 'type') ?? 'generic'),
            inboundMessages: $messages,
            statusUpdates: $status !== null ? [$status] : [],
            receivedAt: $webhook->receivedAt,
            requestId: $webhook->headers['x-request-id'] ?? null,
            ignoredProviderStatuses: $status === null && is_string(data_get($payload, 'message.status') ?? data_get($payload, 'status') ?? data_get($payload, 'message_status'))
                ? [(string) (data_get($payload, 'message.status') ?? data_get($payload, 'status') ?? data_get($payload, 'message_status'))]
                : [],
            payload: $payload,
        );
    }

    public function parseDeliveryStatus(array $payload): ?ProviderStatusUpdateData
    {
        $providerStatus = data_get($payload, 'message.status')
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'message_status');

        if (! is_string($providerStatus) || $providerStatus === '') {
            return null;
        }

        $providerMessageId = data_get($payload, 'message.id')
            ?? data_get($payload, 'external_message_id')
            ?? data_get($payload, 'messageId')
            ?? data_get($payload, 'id');

        if (! is_string($providerMessageId) || $providerMessageId === '') {
            return null;
        }

        $normalizedStatus = match (mb_strtolower($providerStatus)) {
            'queued', 'accepted' => WhatsappMessageStatus::Dispatched,
            'sent' => WhatsappMessageStatus::Sent,
            'delivered' => WhatsappMessageStatus::Delivered,
            'read', 'seen' => WhatsappMessageStatus::Read,
            'failed', 'error' => WhatsappMessageStatus::Failed,
            default => null,
        };

        if ($normalizedStatus === null) {
            return null;
        }

        return new ProviderStatusUpdateData(
            provider: $this->providerName(),
            providerMessageId: $providerMessageId,
            normalizedStatus: $normalizedStatus,
            occurredAt: CarbonImmutable::now(),
            providerStatus: mb_strtolower($providerStatus),
            payload: $payload,
        );
    }

    public function validateWebhookSignature(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): void
    {
    }

    public function healthCheck(WhatsappProviderConfig $configuration): ProviderHealthCheckResult
    {
        return new ProviderHealthCheckResult(
            healthy: true,
            httpStatus: 200,
            latencyMs: 1,
            details: ['provider' => $this->providerName()],
        );
    }

    public function supports(string $feature): bool
    {
        return $this->capabilityMatrix->isImplemented($this->providerName(), $feature);
    }

    private function simulatedFailure(WhatsappProviderConfig $configuration, int $attemptNumber): ?ProviderErrorData
    {
        $testing = $this->testingSettings($configuration);
        $attempts = $this->failureAttempts($testing);

        if (! in_array($attemptNumber, $attempts, true)) {
            return null;
        }

        $errorCode = WhatsappProviderErrorCode::tryFrom((string) ($testing['error_code'] ?? ''))
            ?? WhatsappProviderErrorCode::TransientNetworkError;

        return new ProviderErrorData(
            code: $errorCode,
            message: is_string($testing['message'] ?? null) && $testing['message'] !== ''
                ? (string) $testing['message']
                : sprintf('Falha simulada "%s" no provider fake de WhatsApp.', $errorCode->value),
            retryable: array_key_exists('retryable', $testing)
                ? filter_var($testing['retryable'], FILTER_VALIDATE_BOOL)
                : in_array($errorCode, [
                    WhatsappProviderErrorCode::ProviderUnavailable,
                    WhatsappProviderErrorCode::TimeoutError,
                    WhatsappProviderErrorCode::RateLimit,
                    WhatsappProviderErrorCode::TransientNetworkError,
                ], true),
            httpStatus: is_numeric($testing['http_status'] ?? null) ? (int) $testing['http_status'] : null,
            providerCode: is_string($testing['provider_code'] ?? null) && $testing['provider_code'] !== ''
                ? (string) $testing['provider_code']
                : null,
            requestId: is_string($testing['request_id'] ?? null) && $testing['request_id'] !== ''
                ? (string) $testing['request_id']
                : null,
            details: [
                'testing' => [
                    'provider' => $this->providerName(),
                    'attempt_number' => $attemptNumber,
                    'error_code' => $errorCode->value,
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function testingSettings(WhatsappProviderConfig $configuration): array
    {
        $settings = $configuration->setting('testing', []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param  array<string, mixed>  $testing
     * @return list<int>
     */
    private function failureAttempts(array $testing): array
    {
        if (array_key_exists('fail_on_attempts', $testing)) {
            return array_values(array_filter(array_map(
                static fn (mixed $value): ?int => is_numeric($value) ? max(1, (int) $value) : null,
                (array) $testing['fail_on_attempts'],
            )));
        }

        return $this->failOnFirstAttempt ? [1] : [];
    }
}
