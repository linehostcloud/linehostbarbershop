<?php

namespace Tests\Integration\Communication;

use App\Application\Actions\Communication\GenerateWhatsappMessageDeduplicationKeyAction;
use App\Domain\Client\Models\Client;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GenerateWhatsappMessageDeduplicationKeyActionTest extends TestCase
{
    public function test_it_generates_distinct_keys_for_different_logical_messages(): void
    {
        $action = app(GenerateWhatsappMessageDeduplicationKeyAction::class);
        $occurredAt = CarbonImmutable::parse('2026-03-19 10:00:00');

        $client = Client::make([
            'phone_e164' => '+5511999997001',
        ]);

        $basePayload = [
            'body_text' => 'Mensagem de lembrete de teste.',
            'payload_json' => [
                'template_name' => 'lembrete_padrao',
                'template_language' => 'pt_BR',
            ],
            'thread_key' => 'thread-dedup-1',
        ];

        $first = $action->execute(
            tenantId: 'tenant-a',
            client: $client,
            payload: $basePayload,
            type: 'template',
            occurredAt: $occurredAt,
        );

        $differentBody = $action->execute(
            tenantId: 'tenant-a',
            client: $client,
            payload: array_merge($basePayload, [
                'body_text' => 'Mensagem de reativacao diferente.',
            ]),
            type: 'template',
            occurredAt: $occurredAt,
        );

        $differentThread = $action->execute(
            tenantId: 'tenant-a',
            client: $client,
            payload: array_merge($basePayload, [
                'thread_key' => 'thread-dedup-2',
            ]),
            type: 'template',
            occurredAt: $occurredAt,
        );

        $sameLogicalMessage = $action->execute(
            tenantId: 'tenant-a',
            client: $client,
            payload: $basePayload,
            type: 'template',
            occurredAt: $occurredAt->addMinutes(5),
        );

        $this->assertSame($first['key'], $sameLogicalMessage['key']);
        $this->assertNotSame($first['key'], $differentBody['key']);
        $this->assertNotSame($first['key'], $differentThread['key']);
        $this->assertSame(64, strlen($first['key']));
    }
}
