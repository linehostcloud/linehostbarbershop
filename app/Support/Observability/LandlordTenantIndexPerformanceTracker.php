<?php

namespace App\Support\Observability;

use Throwable;

class LandlordTenantIndexPerformanceTracker
{
    /**
     * @var array<string, int>
     */
    private array $durationsMs = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $failures = [];

    public function measure(string $key, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->durationsMs[$key] = (int) (($this->durationsMs[$key] ?? 0) + $this->elapsedMilliseconds($startedAt));
        }
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->counts[$key] = max(0, (int) ($this->counts[$key] ?? 0) + $by);
    }

    public function setCount(string $key, int $value): void
    {
        $this->counts[$key] = max(0, $value);
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordFailure(string $area, Throwable $throwable, array $context = []): void
    {
        $this->increment('technical_failure_count');
        $this->failures[] = array_filter([
            'area' => $area,
            'error_class' => $throwable::class,
            'message' => $throwable->getMessage(),
            ...$context,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{
     *     durations_ms:array<string, int>,
     *     counts:array<string, int>,
     *     meta:array<string, mixed>,
     *     failures:list<array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        return [
            'durations_ms' => $this->durationsMs,
            'counts' => $this->counts,
            'meta' => $this->meta,
            'failures' => $this->failures,
        ];
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
