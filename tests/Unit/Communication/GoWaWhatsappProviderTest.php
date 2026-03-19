<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\Providers\GoWaWhatsappProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoWaWhatsappProviderTest extends TestCase
{
    public function test_it_sends_text_messages_using_gowa_basic_auth_contract(): void
    {
        Http::fake([
            'https://gowa.example/*' => Http::response([
                'data' => [
                    'id' => 'gowa-msg-123',
                ],
            ], 200),
        ]);

        $provider = app(GoWaWhatsappProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'gowa',
            'base_url' => 'https://gowa.example',
            'timeout_seconds' => 5,
            'settings_json' => [
                'auth_username' => 'admin',
                'auth_password' => 'super-secret',
            ],
        ]);

        $result = $provider->sendText(new OutboundWhatsappMessageData(
            messageId: 'msg-3',
            type: 'text',
            recipientPhoneE164: '+5511999990004',
            threadKey: '+5511999990004',
            bodyText: 'Mensagem via GoWA.',
        ), $configuration);

        $this->assertTrue($result->successful());
        $this->assertSame('gowa-msg-123', $result->providerMessageId);
        $this->assertSame('dispatched', $result->normalizedStatus->value);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://gowa.example/send/message'
                && str_starts_with((string) $request->header('Authorization')[0], 'Basic ')
                && data_get($request->data(), 'phone') === '+5511999990004'
                && data_get($request->data(), 'message') === 'Mensagem via GoWA.';
        });
    }

    public function test_it_normalizes_gowa_webhooks_to_internal_language(): void
    {
        $provider = app(GoWaWhatsappProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'gowa',
            'base_url' => 'https://gowa.example',
            'settings_json' => [
                'auth_username' => 'admin',
                'auth_password' => 'super-secret',
            ],
        ]);

        $normalized = $provider->normalizeWebhook(new ReceivedWhatsappWebhookData(
            provider: 'gowa',
            headers: [],
            payload: [
                'event' => 'message.read',
                'data' => [
                    'id' => 'gowa-msg-123',
                ],
            ],
            rawBody: '{"event":"message.read"}',
            receivedAt: CarbonImmutable::now(),
        ), $configuration);

        $this->assertSame('message.read', $normalized->eventType);
        $this->assertCount(1, $normalized->statusUpdates);
        $this->assertSame('read', $normalized->statusUpdates[0]->normalizedStatus->value);
        $this->assertSame('gowa-msg-123', $normalized->statusUpdates[0]->providerMessageId);
    }
}
