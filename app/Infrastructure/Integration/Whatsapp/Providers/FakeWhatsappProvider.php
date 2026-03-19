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
        private readonly string $name = 'fake',
        private readonly bool $failOnFirstAttempt = false,
        private readonly WhatsappProviderCapabilityMatrix $capabilityMatrix,
    ) {
    }

    public function providerName(): string
    {
        return $this->name;
    }

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult
    {
        $attemptNumber = (int) data_get($message->payload, 'attempt_number', 1);

        if ($this->failOnFirstAttempt && $attemptNumber === 1) {
            throw new WhatsappProviderException(new ProviderErrorData(
                code: WhatsappProviderErrorCode::TransientNetworkError,
                message: 'Falha transiente simulada no provider fake de WhatsApp.',
                retryable: true,
            ));
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
}
