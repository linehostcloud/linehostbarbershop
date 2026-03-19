<?php

namespace App\Domain\Communication\Contracts;

use App\Domain\Communication\Data\NormalizedWhatsappWebhookData;
use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ProviderDispatchResult;
use App\Domain\Communication\Data\ProviderHealthCheckResult;
use App\Domain\Communication\Data\ProviderStatusUpdateData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Models\WhatsappProviderConfig;

interface WhatsappProvider
{
    public function providerName(): string;

    public function sendText(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult;

    public function sendTemplate(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult;

    public function sendMedia(OutboundWhatsappMessageData $message, WhatsappProviderConfig $configuration): ProviderDispatchResult;

    public function normalizeWebhook(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): NormalizedWhatsappWebhookData;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseDeliveryStatus(array $payload): ?ProviderStatusUpdateData;

    public function validateWebhookSignature(ReceivedWhatsappWebhookData $webhook, WhatsappProviderConfig $configuration): void;

    public function healthCheck(WhatsappProviderConfig $configuration): ProviderHealthCheckResult;

    public function supports(string $feature): bool;
}
