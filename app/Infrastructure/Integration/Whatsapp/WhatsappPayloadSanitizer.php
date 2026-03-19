<?php

namespace App\Infrastructure\Integration\Whatsapp;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WhatsappPayloadSanitizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue((string) $key, $value);
        }

        return $sanitized;
    }

    /**
     * @param  array<string, string|null>  $headers
     * @return array<string, string|null>
     */
    public function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $sanitized[$key] = is_string($value)
                ? $this->sanitizeString((string) $key, $value)
                : $value;
        }

        return $sanitized;
    }

    private function sanitizeValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return Arr::isAssoc($value)
                ? $this->sanitize($value)
                : array_map(fn (mixed $item): mixed => $this->sanitizeValue($key, $item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->sanitizeString($key, $value);
    }

    private function sanitizeString(string $key, string $value): string
    {
        $normalizedKey = Str::lower($key);

        if (
            Str::contains($normalizedKey, ['token', 'secret', 'password', 'authorization', 'api_key', 'apikey', 'signature'])
        ) {
            return $this->mask($value);
        }

        return $value;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= 8) {
            return '***';
        }

        return mb_substr($value, 0, 4).'***'.mb_substr($value, -4);
    }
}
