<?php

namespace Tests\Unit\Communication;

use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use Tests\TestCase;

class WhatsappPayloadSanitizerTest extends TestCase
{
    public function test_it_masks_sensitive_values_from_payloads_and_headers(): void
    {
        $sanitizer = app(WhatsappPayloadSanitizer::class);

        $payload = $sanitizer->sanitize([
            'api_key' => '1234567890abcdef',
            'access_token' => 'very-secret-token',
            'nested' => [
                'webhook_secret' => 'ultra-secret',
                'safe' => 'visible',
            ],
        ]);
        $headers = $sanitizer->sanitizeHeaders([
            'authorization' => 'Bearer top-secret-token',
            'x-request-id' => 'req-123',
        ]);

        $this->assertSame('1234***cdef', $payload['api_key']);
        $this->assertSame('very***oken', $payload['access_token']);
        $this->assertSame('ultr***cret', $payload['nested']['webhook_secret']);
        $this->assertSame('visible', $payload['nested']['safe']);
        $this->assertSame('Bear***oken', $headers['authorization']);
        $this->assertSame('req-123', $headers['x-request-id']);
    }
}
