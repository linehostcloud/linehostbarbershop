<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Data\OutboundWhatsappMessageData;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\Providers\WhatsappCloudProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappProviderErrorClassificationTest extends TestCase
{
    public function test_it_classifies_rate_limit_as_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Too many requests'],
            ], 429),
        ]);

        $provider = app(WhatsappCloudProvider::class);

        try {
            $provider->sendText($this->outboundMessage(), $this->cloudConfig());
            $this->fail('Era esperado erro rate_limit.');
        } catch (WhatsappProviderException $exception) {
            $this->assertSame('rate_limit', $exception->error->code->value);
            $this->assertTrue($exception->error->retryable);
        }
    }

    public function test_it_classifies_authentication_error_as_non_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid token'],
            ], 401),
        ]);

        $provider = app(WhatsappCloudProvider::class);

        try {
            $provider->sendText($this->outboundMessage(), $this->cloudConfig());
            $this->fail('Era esperado erro de autenticacao.');
        } catch (WhatsappProviderException $exception) {
            $this->assertSame('authentication_error', $exception->error->code->value);
            $this->assertFalse($exception->error->retryable);
        }
    }

    public function test_it_classifies_timeout_as_retryable_timeout_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Request timed out');
        });

        $provider = app(WhatsappCloudProvider::class);

        try {
            $provider->sendText($this->outboundMessage(), $this->cloudConfig());
            $this->fail('Era esperado timeout.');
        } catch (WhatsappProviderException $exception) {
            $this->assertSame('timeout_error', $exception->error->code->value);
            $this->assertTrue($exception->error->retryable);
        }
    }

    private function outboundMessage(): OutboundWhatsappMessageData
    {
        return new OutboundWhatsappMessageData(
            messageId: 'msg-err-1',
            type: 'text',
            recipientPhoneE164: '+5511999990010',
            threadKey: '+5511999990010',
            bodyText: 'Mensagem de teste.',
        );
    }

    private function cloudConfig(): WhatsappProviderConfig
    {
        return WhatsappProviderConfig::make([
            'provider' => 'whatsapp_cloud',
            'base_url' => 'https://graph.facebook.com',
            'api_version' => 'v22.0',
            'access_token' => 'token-cloud',
            'phone_number_id' => '987654321',
            'timeout_seconds' => 5,
        ]);
    }
}
