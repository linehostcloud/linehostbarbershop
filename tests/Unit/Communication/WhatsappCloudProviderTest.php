<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Data\ReceivedWhatsappWebhookData;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\Providers\WhatsappCloudProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappCloudProviderTest extends TestCase
{
    public function test_it_sends_text_messages_using_the_official_cloud_api_contract(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.cloud.123'],
                ],
            ], 200, [
                'X-Fb-Trace-Id' => 'trace-cloud-1',
            ]),
        ]);

        $provider = app(WhatsappCloudProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v22.0',
            'access_token' => 'cloud-token',
            'phone_number_id' => '987654321',
            'timeout_seconds' => 5,
        ]);

        $result = $provider->sendText(new OutboundWhatsappMessageData(
            messageId: 'msg-1',
            type: 'text',
            recipientPhoneE164: '+5511999990001',
            threadKey: '+5511999990001',
            bodyText: 'Ola, cliente.',
        ), $configuration);

        $this->assertTrue($result->successful());
        $this->assertSame('dispatched', $result->normalizedStatus->value);
        $this->assertSame('wamid.cloud.123', $result->providerMessageId);
        $this->assertSame('trace-cloud-1', $result->requestId);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://graph.facebook.com/v22.0/987654321/messages'
                && $request->hasHeader('Authorization', 'Bearer cloud-token')
                && data_get($request->data(), 'text.body') === 'Ola, cliente.'
                && data_get($request->data(), 'to') === '+5511999990001';
        });
    }

    public function test_it_normalizes_status_webhooks_from_whatsapp_cloud(): void
    {
        $provider = app(WhatsappCloudProvider::class);
        $configuration = WhatsappProviderConfig::make([
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v22.0',
            'access_token' => 'cloud-token',
            'phone_number_id' => '987654321',
        ]);

        $normalized = $provider->normalizeWebhook(new ReceivedWhatsappWebhookData(
            provider: 'whatsapp_cloud',
            headers: ['x-fb-trace-id' => 'trace-cloud-2'],
            payload: [
                'entry' => [[
                    'changes' => [[
                        'value' => [
                            'statuses' => [[
                                'id' => 'wamid.cloud.123',
                                'status' => 'delivered',
                                'recipient_id' => '5511999990001',
                                'timestamp' => '1760000000',
                            ]],
                        ],
                    ]],
                ]],
            ],
            rawBody: '{"entry":[]}',
            receivedAt: CarbonImmutable::now(),
        ), $configuration);

        $this->assertSame('delivery_status', $normalized->eventType);
        $this->assertCount(1, $normalized->statusUpdates);
        $this->assertSame('delivered', $normalized->statusUpdates[0]->normalizedStatus->value);
        $this->assertSame('wamid.cloud.123', $normalized->statusUpdates[0]->providerMessageId);
    }
}
