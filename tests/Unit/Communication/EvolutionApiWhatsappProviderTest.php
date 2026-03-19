<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\Providers\EvolutionApiWhatsappProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionApiWhatsappProviderTest extends TestCase
{
    public function test_it_sends_text_messages_using_evolution_api_contract(): void
    {
        Http::fake([
            'https://evolution.example/*' => Http::response([
                'key' => [
                    'id' => 'evo-msg-123',
                ],
                'status' => 'PENDING',
            ], 200),
        ]);

        $provider = app(EvolutionApiWhatsappProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'evolution_api',
            'base_url' => 'https://evolution.example',
            'api_key' => 'evo-api-key',
            'instance_name' => 'barbearia-demo',
            'timeout_seconds' => 5,
        ]);

        $result = $provider->sendText(new OutboundWhatsappMessageData(
            messageId: 'msg-2',
            type: 'text',
            recipientPhoneE164: '+5511999990002',
            threadKey: '+5511999990002',
            bodyText: 'Mensagem via Evolution.',
        ), $configuration);

        $this->assertTrue($result->successful());
        $this->assertSame('evo-msg-123', $result->providerMessageId);
        $this->assertSame('dispatched', $result->normalizedStatus->value);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://evolution.example/message/sendText/barbearia-demo'
                && $request->hasHeader('apikey', 'evo-api-key')
                && data_get($request->data(), 'number') === '+5511999990002'
                && data_get($request->data(), 'textMessage.text') === 'Mensagem via Evolution.';
        });
    }

    public function test_it_normalizes_evolution_webhooks_without_leaking_payload_shape(): void
    {
        $provider = app(EvolutionApiWhatsappProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'evolution_api',
            'base_url' => 'https://evolution.example',
            'api_key' => 'evo-api-key',
            'instance_name' => 'barbearia-demo',
        ]);

        $normalized = $provider->normalizeWebhook(new ReceivedWhatsappWebhookData(
            provider: 'evolution_api',
            headers: [],
            payload: [
                'event' => 'MESSAGES_UPSERT',
                'data' => [
                    'key' => [
                        'id' => 'evo-in-123',
                        'remoteJid' => '5511999990003@s.whatsapp.net',
                    ],
                    'message' => [
                        'conversation' => 'Inbound via Evolution',
                    ],
                    'messageTimestamp' => 1760001000,
                ],
            ],
            rawBody: '{"event":"MESSAGES_UPSERT"}',
            receivedAt: CarbonImmutable::now(),
        ), $configuration);

        $this->assertSame('MESSAGES_UPSERT', $normalized->eventType);
        $this->assertCount(1, $normalized->inboundMessages);
        $this->assertSame('evo-in-123', $normalized->inboundMessages[0]->providerMessageId);
        $this->assertSame('+5511999990003', $normalized->inboundMessages[0]->phoneE164);
    }
}
