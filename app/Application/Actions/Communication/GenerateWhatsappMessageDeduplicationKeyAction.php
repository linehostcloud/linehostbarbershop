<?php

namespace App\Application\Actions\Communication;

use App\Domain\Client\Models\Client;
use Carbon\CarbonImmutable;

class GenerateWhatsappMessageDeduplicationKeyAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{key:string,window_started_at:string,window_ended_at:string,window_minutes:int}
     */
    public function execute(
        string $tenantId,
        Client $client,
        array $payload,
        string $type,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $occurredAt ??= CarbonImmutable::now(config('app.timezone', 'UTC'));

        $windowMinutes = max(1, (int) config('communication.whatsapp.deduplication.window_minutes', 15));
        $windowSeconds = $windowMinutes * 60;
        $bucketStartTimestamp = (int) (floor($occurredAt->getTimestamp() / $windowSeconds) * $windowSeconds);
        $windowStartedAt = CarbonImmutable::createFromTimestamp($bucketStartTimestamp, $occurredAt->getTimezone());
        $windowEndedAt = $windowStartedAt->addSeconds($windowSeconds);

        $canonicalPayload = [
            'tenant_id' => $tenantId,
            'to' => (string) ($client->phone_e164 ?? ''),
            'type' => $type,
            'body_text' => is_string($payload['body_text'] ?? null) ? trim((string) $payload['body_text']) : null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'automation_id' => $payload['automation_id'] ?? null,
            'campaign_id' => $payload['campaign_id'] ?? null,
            'thread_key' => $payload['thread_key'] ?? null,
            'payload' => $this->normalizePayload($payload['payload_json'] ?? []),
            'window_started_at' => $windowStartedAt->toIso8601String(),
        ];

        return [
            'key' => hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'window_started_at' => $windowStartedAt->toIso8601String(),
            'window_ended_at' => $windowEndedAt->toIso8601String(),
            'window_minutes' => $windowMinutes,
        ];
    }

    /**
     * @param  mixed  $payload
     * @return mixed
     */
    private function normalizePayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        if (array_is_list($payload)) {
            return array_map(fn (mixed $item): mixed => $this->normalizePayload($item), $payload);
        }

        $normalized = [];
        ksort($payload);

        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $this->normalizePayload($value);
        }

        return $normalized;
    }
}
